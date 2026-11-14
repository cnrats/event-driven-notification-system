<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Events;

use App\Modules\Notifications\Events\NotificationStatusChanged;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * A status change as sent to WebSocket subscribers.
 *
 * Three public channels:
 *
 *   notifications               every status change
 *   notifications.{id}          one notification
 *   batches.{batchId}           one batch
 *
 * Public because the demo has no users. With authentication these become
 * private channels scoped to the tenant.
 */
final readonly class NotificationStatusBroadcast implements ShouldBroadcast
{
    public const string QUEUE = 'broadcasts';

    public function __construct(private NotificationStatusChanged $change) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('notifications'),
            new Channel("notifications.{$this->change->notificationId}"),
        ];

        if ($this->change->batchId !== null) {
            $channels[] = new Channel("batches.{$this->change->batchId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'notification.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->change->notificationId,
            'batch_id' => $this->change->batchId,
            'channel' => $this->change->channel->value,
            'status' => $this->change->status->value,
            'attempts' => $this->change->attempts,
            'detail' => $this->change->detail,
            'at' => $this->change->occurredAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * Its own queue and workers: publishing updates must never compete with
     * delivering notifications.
     */
    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }
}
