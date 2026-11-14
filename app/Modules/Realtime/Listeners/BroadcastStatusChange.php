<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Listeners;

use App\Modules\Notifications\Events\NotificationStatusChanged;
use App\Modules\Realtime\Events\NotificationStatusBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a domain event into a WebSocket broadcast.
 *
 * Real-time updates are a convenience on top of the API. If queueing the
 * broadcast fails, the status change itself has already been committed and
 * stays correct; the failure is logged and nothing else happens.
 */
final readonly class BroadcastStatusChange
{
    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    public function handle(NotificationStatusChanged $change): void
    {
        try {
            $this->events->dispatch(new NotificationStatusBroadcast($change));
        } catch (Throwable $e) {
            $this->log->warning('realtime.broadcast_failed', [
                'notification_id' => $change->notificationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
