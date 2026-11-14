<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Controllers;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Queue\QueueRouter;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\DeliveryAttempt;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Observability\Metrics\Metrics;
use App\Support\DbTime;
use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Queue\RedisQueue;

/**
 * Two views of the same system:
 *
 *   GET /api/v1/metrics  JSON snapshot for API consumers and humans
 *   GET /metrics         Prometheus text format, scraped for the dashboard
 */
final readonly class MetricsController
{
    private const int LATENCY_SAMPLE = 10_000;

    public function __construct(
        private Metrics $metrics,
        private QueueFactory $queues,
        private ChannelCircuitBreaker $breaker,
        private ChannelRateLimiter $limiter,
        private NotificationsConfig $config,
    ) {}

    public function json(): JsonResponse
    {
        $now = DbTime::now();
        $queues = $this->queueDepths();
        $rateLimits = [];
        $circuits = [];

        foreach (Channel::cases() as $channel) {
            $rateLimits[$channel->value] = [
                'limit_per_second' => $this->config->ratePerSecond($channel),
                'slot_interval_ms' => round($this->limiter->intervalMicros($channel) / 1000, 3),
            ];
            $circuits[$channel->value] = $this->breaker->state($channel);
        }

        return response()->json([
            'data' => [
                'queues' => [
                    'depth' => array_sum(array_column($queues, 'total')),
                    'by_queue' => $queues,
                ],
                'notifications' => $this->statusCounts(),
                'delivery' => [
                    'last_minute' => $this->deliveryRates($now->subMinute()),
                    'last_5_minutes' => $this->deliveryRates($now->subMinutes(5)),
                ],
                'latency_ms' => [
                    'provider' => $this->providerLatency($now->subMinutes(5)),
                    'end_to_end' => $this->endToEndLatency($now->subMinutes(5)),
                ],
                'rate_limits' => $rateLimits,
                'circuits' => $circuits,
                'generated_at' => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);
    }

    public function prometheus(): Response
    {
        // Gauges describe "now", so they are set at scrape time; counters and
        // histograms were recorded by the API and the workers as things happened.
        foreach ($this->queueDepths() as $queue => $depth) {
            $this->metrics->gauge('queue_depth', 'Jobs waiting or being processed, per queue.', (float) $depth['total'], ['queue'], [$queue]);
        }

        foreach ($this->statusCounts() as $status => $count) {
            $this->metrics->gauge('current', 'Notifications currently in each status.', (float) $count, ['status'], [$status]);
        }

        foreach (Channel::cases() as $channel) {
            $state = ['closed' => 0.0, 'half_open' => 1.0, 'open' => 2.0][$this->breaker->state($channel)] ?? 0.0;
            $this->metrics->gauge('circuit_state', 'Circuit breaker: 0 closed, 1 half-open, 2 open.', $state, ['channel'], [$channel->value]);
            $this->metrics->gauge('rate_limit_per_second', 'Configured ceiling per channel.', (float) $this->config->ratePerSecond($channel), ['channel'], [$channel->value]);
        }

        return response($this->metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /**
     * @return array<string, array{pending: int, delayed: int, reserved: int, total: int}>
     */
    private function queueDepths(): array
    {
        $connection = $this->queues->connection('redis');
        $depths = [];

        foreach (QueueRouter::all() as $queue) {
            if ($connection instanceof RedisQueue) {
                $pending = (int) $connection->pendingSize($queue);
                $delayed = (int) $connection->delayedSize($queue);
                $reserved = (int) $connection->reservedSize($queue);
            } else {
                $pending = $connection->size($queue);
                $delayed = 0;
                $reserved = 0;
            }

            $depths[$queue] = [
                'pending' => $pending,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'total' => $pending + $delayed + $reserved,
            ];
        }

        return $depths;
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counted = Notification::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (NotificationStatus::values() as $status) {
            $total = $counted->get($status);
            $counts[$status] = is_numeric($total) ? (int) $total : 0;
        }

        return $counts;
    }

    /**
     * @return array<string, int|float|null>
     */
    private function deliveryRates(CarbonImmutable $since): array
    {
        $counted = DeliveryAttempt::query()
            ->where('created_at', '>=', DbTime::format($since))
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $rates = [];
        $attempts = 0;

        foreach (['accepted', 'retryable', 'throttled', 'permanent'] as $outcome) {
            $total = $counted->get($outcome);
            $rates[$outcome] = is_numeric($total) ? (int) $total : 0;
            $attempts += $rates[$outcome];
        }

        $rates['attempts'] = $attempts;
        // Throttling is the provider pacing us, not a failed delivery.
        $failed = $rates['retryable'] + $rates['permanent'];
        $rates['success_rate'] = $attempts === 0 ? null : round($rates['accepted'] / $attempts, 4);
        $rates['failure_rate'] = $attempts === 0 ? null : round($failed / $attempts, 4);

        return $rates;
    }

    /**
     * @return array{count: int, p50: float|null, p95: float|null, p99: float|null}
     */
    private function providerLatency(CarbonImmutable $since): array
    {
        $values = DeliveryAttempt::query()
            ->where('created_at', '>=', DbTime::format($since))
            ->whereNotNull('latency_ms')
            ->latest('id')
            ->limit(self::LATENCY_SAMPLE)
            ->pluck('latency_ms')
            ->map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0)
            ->all();

        return $this->percentiles(array_values($values));
    }

    /**
     * @return array{count: int, p50: float|null, p95: float|null, p99: float|null}
     */
    private function endToEndLatency(CarbonImmutable $since): array
    {
        $values = Notification::query()
            ->where('sent_at', '>=', DbTime::format($since))
            ->latest('sent_at')
            ->limit(self::LATENCY_SAMPLE)
            ->selectRaw('TIMESTAMPDIFF(MICROSECOND, created_at, sent_at) / 1000 as latency_ms')
            ->pluck('latency_ms')
            ->map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0)
            ->all();

        return $this->percentiles(array_values($values));
    }

    /**
     * @param  list<float>  $values
     * @return array{count: int, p50: float|null, p95: float|null, p99: float|null}
     */
    private function percentiles(array $values): array
    {
        $count = count($values);

        if ($count === 0) {
            return ['count' => 0, 'p50' => null, 'p95' => null, 'p99' => null];
        }

        sort($values);

        $at = static fn (float $quantile): float => round($values[(int) min($count - 1, floor($quantile * $count))], 1);

        return ['count' => $count, 'p50' => $at(0.50), 'p95' => $at(0.95), 'p99' => $at(0.99)];
    }
}
