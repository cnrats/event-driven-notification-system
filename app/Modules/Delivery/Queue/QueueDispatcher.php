<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Queue;

use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Contracts\DispatchesNotifications;
use App\Modules\Notifications\Contracts\QueueTicket;
use App\Support\DbTime;
use Illuminate\Support\Facades\Bus;

final readonly class QueueDispatcher implements DispatchesNotifications
{
    public function __construct(private NotificationLifecycle $lifecycle) {}

    public function dispatch(array $tickets): void
    {
        if ($tickets === []) {
            return;
        }

        $jobs = array_map(
            static fn (QueueTicket $ticket): DeliverNotification => (new DeliverNotification($ticket->notificationId))
                ->onQueue(QueueRouter::queueFor($ticket->channel, $ticket->priority)),
            $tickets,
        );

        // Bus::bulk groups jobs by queue and pushes each group in one Redis
        // pipeline, so a 1000-item batch is a handful of round trips.
        Bus::bulk($jobs);

        // Only now is the push confirmed. If the process dies before this
        // line the sweeper pushes again; duplicates are dropped by the claim.
        $this->lifecycle->confirmEnqueued(
            array_map(static fn (QueueTicket $ticket): string => $ticket->notificationId, $tickets),
            DbTime::now(),
        );
    }
}
