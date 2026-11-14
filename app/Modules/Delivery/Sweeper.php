<?php

declare(strict_types=1);

namespace App\Modules\Delivery;

use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Contracts\DispatchesNotifications;
use App\Modules\Notifications\Contracts\QueueTicket;
use App\Support\DbTime;
use App\Support\NotificationsConfig;

/**
 * Moves rows from the database into the queue when they are due, and puts
 * back anything that fell through a crack.
 *
 * Everything time-based is driven from the database, not from delayed jobs
 * in Redis: a scheduled notification and a retry are both just "a row that is
 * due at T". One mechanism, one source of truth, and a Redis restart cannot
 * lose a schedule or a retry.
 *
 * The four passes:
 *
 *   due          scheduled / retrying rows whose time has come
 *   leases       processing rows whose worker died (lease expired)
 *   unconfirmed  queued rows whose push to Redis was never confirmed
 *   stale        queued rows pushed long ago and still untouched (job lost)
 *
 * Every pass may enqueue a notification that already has a job. That is
 * safe by design: the claim in DeliveryHandler lets only one job deliver.
 */
final readonly class Sweeper
{
    public function __construct(
        private NotificationLifecycle $lifecycle,
        private DispatchesNotifications $dispatcher,
        private NotificationsConfig $config,
    ) {}

    /**
     * @return array{due: int, leases: int, unconfirmed: int, stale: int}
     */
    public function sweep(): array
    {
        $chunk = $this->config->sweeperChunk;

        return [
            'due' => $this->drain(fn (): array => $this->lifecycle->promoteDue(DbTime::now(), $chunk)),
            'leases' => $this->drain(fn (): array => $this->lifecycle->requeueExpiredLeases(
                DbTime::now(),
                $this->config->sweeperLeaseGraceSeconds,
                $chunk,
            )),
            'unconfirmed' => $this->once(fn (): array => $this->lifecycle->findUnconfirmed(
                DbTime::now(),
                $this->config->sweeperUnqueuedGraceSeconds,
                $chunk,
            )),
            'stale' => $this->once(fn (): array => $this->lifecycle->findStale(
                DbTime::now(),
                $this->config->sweeperStaleAfterSeconds,
                $chunk,
            )),
        ];
    }

    /**
     * Repeat a pass until it finds less than a full chunk, so a large number
     * of due rows (a big scheduled campaign) is promoted in one sweep.
     *
     * @param  callable(): list<QueueTicket>  $find
     */
    private function drain(callable $find): int
    {
        $total = 0;

        do {
            $tickets = $find();
            $this->dispatcher->dispatch($tickets);
            $total += count($tickets);
        } while (count($tickets) >= $this->config->sweeperChunk);

        return $total;
    }

    /**
     * @param  callable(): list<QueueTicket>  $find
     */
    private function once(callable $find): int
    {
        $tickets = $find();
        $this->dispatcher->dispatch($tickets);

        return count($tickets);
    }
}
