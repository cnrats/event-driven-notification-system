<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Queue\QueueRouter;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\Notification;
use App\Support\NotificationsConfig;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Tools shared by the scenarios: a clean slate, the public API, the mock
 * provider's admin API and a few measurements.
 */
final readonly class ScenarioKit
{
    public function __construct(
        private Factory $http,
        private Connection $redis,
        private ChannelRateLimiter $limiter,
        private ChannelCircuitBreaker $breaker,
        public NotificationsConfig $config,
        private string $apiUrl,
    ) {}

    /**
     * Start from nothing: no notifications, empty queues, closed circuits, a
     * well-behaved provider. Scenarios run on demo data only.
     */
    public function reset(): void
    {
        $this->waitUntil(fn (): bool => $this->count(NotificationStatus::Processing) === 0, 30);

        foreach (['delivery_attempts', 'notifications', 'notification_batches', 'failed_jobs'] as $table) {
            DB::table($table)->truncate();
        }

        foreach (QueueRouter::all() as $queue) {
            $this->redis->command('del', ["queues:{$queue}", "queues:{$queue}:delayed", "queues:{$queue}:reserved", "queues:{$queue}:notify"]);
        }

        foreach (Channel::cases() as $channel) {
            $this->limiter->reset($channel);
            $this->breaker->reset($channel);
        }

        $this->providerPost('/__admin/reset', []);
    }

    /**
     * Change how the mock provider behaves (see mock-provider/index.php).
     *
     * @param  array<string, int|float>  $settings
     */
    public function provider(array $settings): void
    {
        $this->providerPost('/__admin/config', $settings);
    }

    /**
     * What the provider saw: the ground truth for rate and duplicates.
     *
     * @return array<string, mixed>
     */
    public function providerStats(): array
    {
        $stats = $this->http->timeout(30)->get($this->providerUrl().'/__admin/stats')->throw()->json();

        if (! is_array($stats)) {
            throw new RuntimeException('The mock provider returned no statistics.');
        }

        /** @var array<string, mixed> $stats */
        return $stats;
    }

    /**
     * Requests the provider received on one channel, and the most it saw in
     * any one-second window.
     *
     * @param  array<string, mixed>  $stats
     * @return array{requests: int, max_in_any_second: int, throttled: int, failed: int}
     */
    public function channelStats(array $stats, Channel $channel): array
    {
        $channels = is_array($stats['channels'] ?? null) ? $stats['channels'] : [];
        $one = is_array($channels[$channel->value] ?? null) ? $channels[$channel->value] : [];

        $int = static fn (string $key): int => is_numeric($one[$key] ?? null) ? (int) $one[$key] : 0;

        return [
            'requests' => $int('requests'),
            'max_in_any_second' => $int('max_in_any_second'),
            'throttled' => $int('throttled'),
            'failed' => $int('failed'),
        ];
    }

    /**
     * POST /api/v1/notifications/batch through the real HTTP API.
     *
     * @param  list<array<string, mixed>>  $items
     * @return string the batch id
     */
    public function submitBatch(array $items, ?string $idempotencyKey = null): string
    {
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];

        $response = $this->http->timeout(60)->withHeaders($headers)
            ->post($this->apiUrl.'/api/v1/notifications/batch', ['notifications' => $items])
            ->throw();

        $batchId = $response->json('data.batch_id');

        if (! is_string($batchId)) {
            throw new RuntimeException('The API did not return a batch id.');
        }

        return $batchId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function post(string $path, array $payload = [], array $headers = []): Response
    {
        return $this->http->timeout(30)->withHeaders($headers)->post($this->apiUrl.$path, $payload);
    }

    public function api(): string
    {
        return $this->apiUrl;
    }

    public function http(): Factory
    {
        return $this->http;
    }

    /**
     * Poll until the condition holds.
     *
     * @param  callable(): bool  $condition
     */
    public function waitUntil(callable $condition, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            Sleep::usleep(250_000);
        }

        return $condition();
    }

    /**
     * Wait until no notification is waiting or in flight any more.
     */
    public function waitUntilFinished(int $timeoutSeconds): bool
    {
        return $this->waitUntil(fn (): bool => $this->unfinished() === 0, $timeoutSeconds);
    }

    public function unfinished(): int
    {
        return Notification::query()->whereNotIn('status', [
            NotificationStatus::Sent->value,
            NotificationStatus::Failed->value,
            NotificationStatus::Cancelled->value,
        ])->count();
    }

    public function count(NotificationStatus $status): int
    {
        return Notification::query()->where('status', $status->value)->count();
    }

    /**
     * How many notifications needed how many delivery attempts.
     *
     * @return array<int, int> attempts => notifications
     */
    public function attemptsDistribution(): array
    {
        $rows = Notification::query()
            ->selectRaw('attempts, COUNT(*) as total')
            ->groupBy('attempts')
            ->orderBy('attempts')
            ->pluck('total', 'attempts');

        $distribution = [];

        foreach ($rows as $attempts => $total) {
            $distribution[(int) $attempts] = is_numeric($total) ? (int) $total : 0;
        }

        return $distribution;
    }

    /**
     * The most delivery attempts any notification has used.
     */
    public function maxAttemptsUsed(): int
    {
        $max = Notification::query()->max('attempts');

        return is_numeric($max) ? (int) $max : 0;
    }

    public function failedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * Worker processes per channel as configured for this run.
     */
    public function workersPerChannel(): int
    {
        return $this->config->workersPerChannel + $this->config->fairWorkersPerChannel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerPost(string $path, array $payload): void
    {
        $this->http->timeout(30)->asJson()->post($this->providerUrl().$path, $payload)->throw();
    }

    /**
     * Admin API of the mock provider, next to the delivery endpoint.
     */
    private function providerUrl(): string
    {
        $parts = parse_url($this->config->providerUrl);
        $parts = $parts === false ? [] : $parts;
        $host = $parts['host'] ?? '';

        if ($host === '' || str_contains($host, 'webhook.site')) {
            throw new RuntimeException(
                'Scenarios need the bundled mock provider. Set NOTIFICATION_PROVIDER_URL back to http://mock-provider:9000/send.',
            );
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $scheme = $parts['scheme'] ?? 'http';

        return "{$scheme}://{$host}{$port}";
    }
}
