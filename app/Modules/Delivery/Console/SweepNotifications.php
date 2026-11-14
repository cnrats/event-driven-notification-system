<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Console;

use App\Modules\Delivery\Sweeper;
use App\Support\NotificationsConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Long-running sweeper process (its own container).
 *
 * A plain loop instead of the Laravel scheduler: the scheduler only starts
 * work on the minute boundary, so the first sweep after a deploy could be a
 * minute late, and its overlap lock outlives a killed process. A loop starts
 * immediately and, thanks to SKIP LOCKED, several can run side by side.
 */
#[Description('Enqueue due notifications and recover stranded ones')]
#[Signature('notifications:sweep {--once : Run a single sweep and exit}')]
final class SweepNotifications extends Command
{
    public const string HEARTBEAT_KEY = 'sweeper:heartbeat';

    private bool $running = true;

    public function handle(Sweeper $sweeper, NotificationsConfig $config, Cache $cache, LoggerInterface $log): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });

        while ($this->running) {
            try {
                $moved = $sweeper->sweep();
                $cache->put(self::HEARTBEAT_KEY, time(), 60);

                if (array_sum($moved) > 0) {
                    $log->info('sweeper.moved', $moved);
                }
            } catch (Throwable $e) {
                // Database or Redis hiccup: log and try again on the next
                // tick instead of crash-looping the container.
                $log->error('sweeper.failed', ['error' => $e->getMessage()]);
            }

            if ($this->option('once') === true) {
                break;
            }

            Sleep::usleep($config->sweeperIntervalMs * 1000);
        }

        return self::SUCCESS;
    }
}
