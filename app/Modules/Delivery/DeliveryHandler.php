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
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\DeliveryAttempt;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Observability\Metrics\Metrics;
use App\Support\DbTime;
use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers one notification. Called by the DeliverNotification job.
 *
 * Order of the steps matters:
 *
 *   1. Skip jobs whose row is already final, not due, or held by a live lease.
 *   2. Ask the circuit breaker. Open: put the job back, no attempt spent.
 *   3. Take a rate-limit slot and wait for it in-process.
 *   4. Claim the row (conditional UPDATE). Exactly one worker wins.
 *   5. Call the provider with the notification ID as idempotency key.
 *   6. Record the attempt and move the row to sent / retrying / failed.
 *
 * Steps 2 and 3 come before the claim so that waiting never consumes a
 * delivery attempt. The claim comes last before the send so that a cancel
 * issued while the job was waiting still wins.
 */
final readonly class DeliveryHandler
{
    public function __construct(
        private NotificationLifecycle $lifecycle,
        private NotificationProvider $provider,
        private ChannelRateLimiter $limiter,
        private ChannelCircuitBreaker $breaker,
        private RetryPolicy $retryPolicy,
        private NotificationsConfig $config,
        private Metrics $metrics,
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

        $slot = $this->limiter->reserve($channel);

        if (! $slot->granted) {
            // The channel is paused (provider sent 429). Nothing was reserved.
            $this->giveBackProbe($circuit->probe, $notification);
            $this->metrics->released($channel, 'throttled');

            return DeliveryOutcome::release((int) ceil($slot->waitSeconds()), 'throttled');
        }

        if ($slot->waitMicros > 0) {
            Sleep::usleep($slot->waitMicros);
        }
        $this->metrics->rateLimiterWait($channel, $slot->waitSeconds());

        if (! $this->lifecycle->claim($notification, DbTime::now(), $this->config->leaseSeconds)) {
            // Cancelled, or another worker claimed it, while this job waited.
            $this->giveBackProbe($circuit->probe, $notification);

            return DeliveryOutcome::done('not_claimable');
        }

        $result = $this->provider->send(new OutboundMessage(
            notificationId: $notification->id,
            channel: $channel,
            recipient: $notification->recipient,
            content: $notification->content,
            correlationId: $notification->correlation_id,
            traceContext: $notification->trace_context,
        ));

        $this->record($notification, $result);
        $this->updateCircuit($notification, $result, $circuit->probe);
        $this->apply($notification, $result);

        return DeliveryOutcome::done($result->outcome->value);
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
