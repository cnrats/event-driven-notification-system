<?php

declare(strict_types=1);

namespace App\Modules\Observability\Metrics;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;
use Closure;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Redis as RedisStorage;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;

/**
 * Application metrics in Prometheus format.
 *
 * PHP processes share nothing, so counters and histograms live in Redis and
 * every API request and worker adds to the same series. Recording a metric
 * must never break a delivery: any storage error is logged and swallowed.
 */
final class Metrics
{
    private const string NAMESPACE = 'notifications';

    /** Seconds; covers a local mock (a few ms) up to a slow real provider. */
    private const array PROVIDER_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5];

    /** Seconds from creation to delivery; long tail for queued bursts and retries. */
    private const array DELIVERY_BUCKETS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 900];

    /** Seconds a worker waited for its rate-limit slot. */
    private const array WAIT_BUCKETS = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2];

    private ?CollectorRegistry $registry = null;

    private bool $failureLogged = false;

    /**
     * @param  Closure(): Redis  $connect  opens the dedicated metrics connection
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly LoggerInterface $log,
    ) {}

    public function created(Channel $channel, Priority $priority, int $count = 1): void
    {
        $this->safely(fn (CollectorRegistry $registry) => $registry
            ->getOrRegisterCounter(self::NAMESPACE, 'created_total', 'Notifications accepted by the API.', ['channel', 'priority'])
            ->incBy($count, [$channel->value, $priority->value]));
    }

    /**
     * One call to the provider. Outcome: accepted | retryable | throttled | permanent.
     */
    public function deliveryAttempt(Channel $channel, string $outcome, float $providerSeconds): void
    {
        $this->safely(function (CollectorRegistry $registry) use ($channel, $outcome, $providerSeconds): void {
            $registry
                ->getOrRegisterCounter(self::NAMESPACE, 'delivery_attempts_total', 'Calls to the provider by outcome.', ['channel', 'outcome'])
                ->inc([$channel->value, $outcome]);

            $registry
                ->getOrRegisterHistogram(self::NAMESPACE, 'provider_request_duration_seconds', 'Provider response time.', ['channel'], self::PROVIDER_BUCKETS)
                ->observe($providerSeconds, [$channel->value]);
        });
    }

    /**
     * A notification reached a final state: sent | failed | cancelled.
     */
    public function finished(Channel $channel, Priority $priority, string $status, ?float $secondsSinceCreation = null): void
    {
        $this->safely(function (CollectorRegistry $registry) use ($channel, $priority, $status, $secondsSinceCreation): void {
            $registry
                ->getOrRegisterCounter(self::NAMESPACE, 'finished_total', 'Notifications that reached a final state.', ['channel', 'status'])
                ->inc([$channel->value, $status]);

            if ($secondsSinceCreation !== null) {
                $registry
                    ->getOrRegisterHistogram(self::NAMESPACE, 'delivery_latency_seconds', 'Time from acceptance to delivery.', ['channel', 'priority'], self::DELIVERY_BUCKETS)
                    ->observe($secondsSinceCreation, [$channel->value, $priority->value]);
            }
        });
    }

    public function rateLimiterWait(Channel $channel, float $seconds): void
    {
        $this->safely(fn (CollectorRegistry $registry) => $registry
            ->getOrRegisterHistogram(self::NAMESPACE, 'rate_limiter_wait_seconds', 'Time a worker waited for its rate-limit slot.', ['channel'], self::WAIT_BUCKETS)
            ->observe($seconds, [$channel->value]));
    }

    /**
     * A job was put back without a delivery attempt: circuit_open | throttled | lease_held.
     */
    public function released(Channel $channel, string $reason): void
    {
        $this->safely(fn (CollectorRegistry $registry) => $registry
            ->getOrRegisterCounter(self::NAMESPACE, 'jobs_released_total', 'Jobs put back on the queue without a delivery attempt.', ['channel', 'reason'])
            ->inc([$channel->value, $reason]));
    }

    public function circuitOpened(Channel $channel): void
    {
        $this->safely(fn (CollectorRegistry $registry) => $registry
            ->getOrRegisterCounter(self::NAMESPACE, 'circuit_opened_total', 'Times the circuit breaker opened.', ['channel'])
            ->inc([$channel->value]));
    }

    /**
     * Set a point-in-time value just before rendering (queue depth etc.).
     *
     * @param  list<string>  $labelNames
     * @param  list<string>  $labelValues
     */
    public function gauge(string $name, string $help, float $value, array $labelNames = [], array $labelValues = []): void
    {
        $this->safely(fn (CollectorRegistry $registry) => $registry
            ->getOrRegisterGauge(self::NAMESPACE, $name, $help, $labelNames)
            ->set($value, $labelValues));
    }

    /**
     * Everything recorded so far, in Prometheus text exposition format.
     */
    public function render(): string
    {
        $renderer = new RenderTextFormat;

        return $renderer->render($this->registry()->getMetricFamilySamples());
    }

    public function wipe(): void
    {
        $this->registry()->wipeStorage();
    }

    /**
     * @param  Closure(CollectorRegistry): mixed  $record
     */
    private function safely(Closure $record): void
    {
        try {
            $record($this->registry());
        } catch (Throwable $e) {
            // Drop the connection so the next call reconnects, and log once
            // per process instead of once per delivery.
            $this->registry = null;

            if (! $this->failureLogged) {
                $this->failureLogged = true;
                $this->log->warning('metrics.unavailable', ['error' => $e->getMessage()]);
            }
        }
    }

    private function registry(): CollectorRegistry
    {
        return $this->registry ??= new CollectorRegistry(
            RedisStorage::fromExistingConnection(($this->connect)()),
            false,
        );
    }
}
