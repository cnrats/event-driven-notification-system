<?php

declare(strict_types=1);

namespace App\Modules\Delivery;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Provider\NotificationProvider;
use App\Modules\Delivery\Provider\OutboundMessage;
use App\Modules\Delivery\Provider\ProviderOutcome;
use App\Modules\Delivery\Provider\ProviderResult;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Delivery\Retry\RetryPolicy;
use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\DeliveryAttempt;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Observability\Metrics\Metrics;
use App\Modules\Observability\Tracing\Tracing;
use App\Support\DbTime;
use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Sleep;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers one notification. Called by the DeliverNotification job.
 *
 * Order of the steps matters:
 *
 *   1. Skip jobs whose row is already final, not due, or held by a live lease.
 *   2. Ask the circuit breaker. Open: put the job back, no attempt spent.
 *   3. Claim the row (conditional UPDATE). Exactly one worker wins; a
 *      notification cancelled in the meantime is not claimable.
 *   4. Take a rate-limit slot and wait for it in-process (a worker that
 *      wakes up late takes a fresh slot). If the channel is paused instead,
 *      the claim is undone and the attempt given back.
 *   5. Call the provider with the notification ID as idempotency key.
 *   6. Record the attempt and move the row to sent / retrying / failed.
 *
 * Waiting (steps 2 and 4) never consumes a delivery attempt, and the wait
 * for the slot is the last thing before the request so the spacing the
 * limiter hands out is the spacing the provider sees.
 */
final readonly class DeliveryHandler
{
    /** How often a worker may take a new slot after waking up too late. */
    private const int SLOT_TRIES = 3;

    public function __construct(
        private NotificationLifecycle $lifecycle,
        private NotificationProvider $provider,
        private ChannelRateLimiter $limiter,
        private ChannelCircuitBreaker $breaker,
        private RetryPolicy $retryPolicy,
        private NotificationsConfig $config,
        private Metrics $metrics,
        private Tracing $tracing,
        private LoggerInterface $log,
    ) {}

    public function deliver(string $notificationId): DeliveryOutcome
    {
        $notification = Notification::query()->find($notificationId);

        if (! $notification instanceof Notification) {
            return DeliveryOutcome::done('missing');
        }

        // Jobs enqueued by the sweeper carry no request context; restore it
        // from the row so worker logs join the request that created it.
        Context::add('notification_id', $notification->id);
        if ($notification->correlation_id !== null) {
            Context::add('correlation_id', $notification->correlation_id);
        }

        // The trace of the request that created the notification continues
        // here, in another process and possibly much later.
        if ($notification->trace_context === null) {
            return $this->process($notification);
        }

        return $this->tracing->span(
            'notification.deliver',
            [
                'notification.id' => $notification->id,
                'notification.channel' => $notification->channel->value,
                'notification.priority' => $notification->priority->value,
                'notification.attempt' => $notification->attempts + 1,
                'correlation_id' => $notification->correlation_id,
            ],
            function (SpanInterface $span) use ($notification): DeliveryOutcome {
                $outcome = $this->process($notification);
                $span->setAttribute('delivery.outcome', $outcome->reason);

                return $outcome;
            },
            SpanKind::KIND_CONSUMER,
            $notification->trace_context,
        );
    }

    private function process(Notification $notification): DeliveryOutcome
    {
        $now = DbTime::now();
        $skip = $this->reasonToSkip($notification, $now);

        if ($skip instanceof DeliveryOutcome) {
            return $skip;
        }

        if ($now->greaterThanOrEqualTo($notification->expires_at)) {
            $this->fail($notification, 'expired: not delivered within its time to live', $now);

            return DeliveryOutcome::done('expired');
        }

        $channel = $notification->channel;
        $circuit = $this->breaker->allow($channel);

        if (! $circuit->allowed) {
            $this->metrics->released($channel, 'circuit_open');

            return DeliveryOutcome::release($circuit->retryAfterSeconds, 'circuit_open');
        }

        if (! $this->lifecycle->claim($notification, DbTime::now(), $this->config->leaseSeconds)) {
            // Cancelled, or claimed by another worker, since the job was pushed.
            $this->giveBackProbe($circuit->probe, $notification);

            return DeliveryOutcome::done('not_claimable');
        }

        $pausedFor = $this->tracing->childSpan(
            'rate_limit.wait',
            ['notification.channel' => $channel->value],
            fn (): ?int => $this->waitForSlot($channel),
        );

        if ($pausedFor !== null) {
            // The channel is paused (provider sent 429). No slot was taken;
            // hand the row back as if it had never been claimed.
            $this->lifecycle->unclaim($notification);
            $this->giveBackProbe($circuit->probe, $notification);
            $this->metrics->released($channel, 'throttled');

            return DeliveryOutcome::release($pausedFor, 'throttled');
        }

        $result = $this->tracing->childSpan(
            'provider.send',
            ['notification.id' => $notification->id, 'notification.channel' => $channel->value],
            function (SpanInterface $span) use ($notification, $channel): ProviderResult {
                $result = $this->provider->send(new OutboundMessage(
                    notificationId: $notification->id,
                    channel: $channel,
                    recipient: $notification->recipient,
                    content: $notification->content,
                    correlationId: $notification->correlation_id,
                    // Sent as the traceparent header, so a provider that
                    // traces can attach its spans to ours.
                    traceContext: $this->tracing->traceparent(),
                ));

                $span->setAttribute('provider.outcome', $result->outcome->value);
                $span->setAttribute('http.response.status_code', $result->httpStatus);

                return $result;
            },
            SpanKind::KIND_CLIENT,
        );

        $this->record($notification, $result);
        $this->updateCircuit($notification, $result, $circuit->probe);
        $this->apply($notification, $result);

        return DeliveryOutcome::done($result->outcome->value);
    }

    /**
     * Take a rate-limit slot and wait for it, as the very last step before
     * the request, so nothing with variable duration (a database write) sits
     * between the slot and the moment the provider sees the message.
     *
     * The limiter spaces the slots; the sender has to hit them. A worker that
     * wakes up noticeably late (CPU contention, garbage collection) would
     * send its message bunched together with the ones behind it, and enough
     * of that pushes a one-second window over the limit at the provider. So
     * a late worker gives its slot up and takes a fresh one.
     *
     * @return int|null null once a slot was taken and waited for; otherwise the
     *                  seconds until the paused channel hands out slots again
     */
    private function waitForSlot(Channel $channel): ?int
    {
        $toleranceNanos = $this->config->rateMaxLatenessMs * 1_000_000;
        $waited = 0.0;

        for ($try = 1; $try <= self::SLOT_TRIES; $try++) {
            $slot = $this->limiter->reserve($channel);

            if (! $slot->granted) {
                return (int) ceil($slot->waitSeconds());
            }

            $dueAt = hrtime(true) + $slot->waitMicros * 1000;

            if ($slot->waitMicros > 0) {
                Sleep::usleep($slot->waitMicros);
            }
            $waited += $slot->waitSeconds();

            if (hrtime(true) - $dueAt <= $toleranceNanos) {
                break;
            }

            $this->metrics->slotMissed($channel);
        }

        $this->metrics->rateLimiterWait($channel, $waited);

        return null;
    }

    /**
     * Laravel gave up on the job itself (three uncaught exceptions or
     * crashes). Make sure the notification does not stay "processing" forever.
     */
    public function abandon(string $notificationId, ?Throwable $exception): void
    {
        $notification = Notification::query()->find($notificationId);

        if (! $notification instanceof Notification) {
            return;
        }

        $reason = $exception instanceof Throwable
            ? sprintf('job failed: %s: %s', $exception::class, $exception->getMessage())
            : 'job failed';

        $this->fail($notification, $reason, DbTime::now());
    }

    /**
     * A job can arrive for a row that needs no work: duplicates are expected
     * (sweeper re-enqueues, queue redelivery after a crash).
     */
    private function reasonToSkip(Notification $notification, CarbonImmutable $now): ?DeliveryOutcome
    {
        return match ($notification->status) {
            NotificationStatus::Queued => null,
            NotificationStatus::Processing => $this->leaseOutcome($notification, $now),
            // Final, or waiting for its due time (the sweeper will enqueue it).
            default => DeliveryOutcome::done('status_'.$notification->status->value),
        };
    }

    private function leaseOutcome(Notification $notification, CarbonImmutable $now): ?DeliveryOutcome
    {
        $lockedUntil = $notification->locked_until;

        if (! $lockedUntil instanceof CarbonImmutable || $lockedUntil->lessThanOrEqualTo($now)) {
            // The previous worker died mid-delivery; this job takes over.
            return null;
        }

        // Another worker is on it, or it crashed moments ago. Come back when
        // the lease is over rather than dropping the job.
        $this->metrics->released($notification->channel, 'lease_held');

        return DeliveryOutcome::release((int) ceil($now->diffInSeconds($lockedUntil)) + 1, 'lease_held');
    }

    private function record(Notification $notification, ProviderResult $result): void
    {
        DeliveryAttempt::query()->insert([
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'attempt' => $notification->attempts,
            'outcome' => $result->outcome->value,
            'http_status' => $result->httpStatus,
            'latency_ms' => (int) round($result->latencySeconds * 1000),
            'error' => $result->error === null ? null : mb_strimwidth($result->error, 0, 500, '…'),
            'created_at' => DbTime::format(DbTime::now()),
        ]);

        $this->metrics->deliveryAttempt($notification->channel, $result->outcome->value, $result->latencySeconds);
    }

    private function updateCircuit(Notification $notification, ProviderResult $result, bool $probe): void
    {
        $channel = $notification->channel;

        if ($result->outcome === ProviderOutcome::Retryable) {
            if ($this->breaker->recordFailure($channel, $probe)) {
                $this->metrics->circuitOpened($channel);
                $this->log->warning('circuit.opened', ['channel' => $channel->value, 'error' => $result->error]);
            }

            return;
        }

        // Any answer from the provider, even a rejection, shows it is alive.
        $this->breaker->recordSuccess($channel, $probe);
    }

    private function apply(Notification $notification, ProviderResult $result): void
    {
        $now = DbTime::now();
        $error = $result->error ?? 'unknown provider error';

        switch ($result->outcome) {
            case ProviderOutcome::Accepted:
                if ($this->lifecycle->markSent($notification, $result->messageId, $now)) {
                    $this->metrics->finished(
                        $notification->channel,
                        $notification->priority,
                        NotificationStatus::Sent->value,
                        $notification->created_at->diffInMilliseconds($now) / 1000,
                    );
                    $this->log->info('notification.sent', $this->logContext($notification, $result));
                }

                return;

            case ProviderOutcome::Throttled:
                // Not a failed delivery: the provider is healthy and told us
                // when to come back. Pause the whole channel and refund the
                // attempt so throttling can never exhaust the retry budget.
                $wait = $result->retryAfterSeconds ?? 1;
                $this->limiter->pause($notification->channel, $wait);
                $this->lifecycle->scheduleRetry($notification, $now->addSeconds($wait), $error, $now, refundAttempt: true);
                $this->log->notice('notification.throttled', $this->logContext($notification, $result) + ['retry_after' => $wait]);

                return;

            case ProviderOutcome::Retryable:
                if ($notification->attempts >= $notification->max_attempts) {
                    $this->fail($notification, sprintf('gave up after %d attempts: %s', $notification->attempts, $error), $now);

                    return;
                }

                $delay = $this->retryPolicy->delaySeconds($notification->attempts);
                $this->lifecycle->scheduleRetry($notification, $now->addSeconds($delay), $error, $now);
                $this->log->notice('notification.retry_scheduled', $this->logContext($notification, $result) + ['retry_in' => $delay]);

                return;

            case ProviderOutcome::Permanent:
                $this->fail($notification, $error, $now);
        }
    }

    private function fail(Notification $notification, string $error, CarbonImmutable $now): void
    {
        if ($this->lifecycle->failFromAnyActiveState($notification, $error, $now)) {
            $this->metrics->finished($notification->channel, $notification->priority, NotificationStatus::Failed->value);
            $this->log->warning('notification.failed', [
                'notification_id' => $notification->id,
                'channel' => $notification->channel->value,
                'attempt' => $notification->attempts,
                'error' => $error,
            ]);
        }
    }

    private function giveBackProbe(bool $probe, Notification $notification): void
    {
        if ($probe) {
            $this->breaker->abandonProbe($notification->channel);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(Notification $notification, ProviderResult $result): array
    {
        return [
            'notification_id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'attempt' => $notification->attempts,
            'http_status' => $result->httpStatus,
            'latency_ms' => (int) round($result->latencySeconds * 1000),
            'error' => $result->error,
        ];
    }
}
