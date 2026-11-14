<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Controllers;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Console\SweepNotifications;
use App\Modules\Notifications\Domain\Channel;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * GET /api/v1/health
 *
 *   ok        everything works
 *   degraded  the API accepts requests but nothing is being delivered
 *             (workers or sweeper are not running); still HTTP 200, because
 *             accepted notifications are safe in the database
 *   down      MySQL or Redis is unreachable; HTTP 503
 */
final readonly class HealthController
{
    private const int SWEEPER_MAX_SILENCE_SECONDS = 15;

    public function __construct(
        private Cache $cache,
        private MasterSupervisorRepository $supervisors,
        private ChannelCircuitBreaker $breaker,
    ) {}

    public function __invoke(): JsonResponse
    {
        $database = $this->check(static function (): void {
            DB::select('SELECT 1');
        });
        $redis = $this->check(static function (): void {
            Redis::connection()->command('ping');
        });

        $workers = ['status' => 'unknown'];
        $sweeper = ['status' => 'unknown'];
        $circuits = [];

        if ($redis['status'] === 'ok') {
            $workers = $this->workers();
            $sweeper = $this->sweeper();

            foreach (Channel::cases() as $channel) {
                $circuits[$channel->value] = $this->breaker->state($channel);
            }
        }

        $status = match (true) {
            $database['status'] !== 'ok' || $redis['status'] !== 'ok' => 'down',
            $workers['status'] !== 'ok' || $sweeper['status'] !== 'ok' => 'degraded',
            default => 'ok',
        };

        return response()->json([
            'status' => $status,
            'checks' => [
                'database' => $database,
                'redis' => $redis,
                'workers' => $workers,
                'sweeper' => $sweeper,
            ],
            'circuits' => $circuits,
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ], $status === 'down' ? 503 : 200);
    }

    /**
     * @return array{status: string, supervisors?: int}
     */
    private function workers(): array
    {
        try {
            $running = 0;

            foreach ($this->supervisors->all() as $master) {
                if (is_object($master) && ($master->status ?? null) === 'running') {
                    $running++;
                }
            }

            return ['status' => $running > 0 ? 'ok' : 'not_running', 'supervisors' => $running];
        } catch (Throwable) {
            return ['status' => 'unknown'];
        }
    }

    /**
     * @return array{status: string, last_sweep_seconds_ago?: int}
     */
    private function sweeper(): array
    {
        $heartbeat = $this->cache->get(SweepNotifications::HEARTBEAT_KEY);

        // The Redis cache store returns numbers as strings.
        if (! is_numeric($heartbeat)) {
            return ['status' => 'not_running'];
        }

        $age = max(0, time() - (int) $heartbeat);

        return [
            'status' => $age <= self::SWEEPER_MAX_SILENCE_SECONDS ? 'ok' : 'stalled',
            'last_sweep_seconds_ago' => $age,
        ];
    }

    /**
     * @param  callable(): void  $probe
     * @return array{status: string, error?: string}
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
