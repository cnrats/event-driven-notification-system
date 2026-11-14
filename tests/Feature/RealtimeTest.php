<?php

declare(strict_types=1);

use App\Modules\Notifications\Events\NotificationStatusChanged;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Realtime\Events\NotificationStatusBroadcast;
use App\Modules\Realtime\Listeners\BroadcastStatusChange;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    // Real-time is switched off for the rest of the suite (see phpunit.xml).
    Event::listen(NotificationStatusChanged::class, BroadcastStatusChange::class);
});

it('broadcasts each status change on the general, the notification and the batch channel', function (): void {
    $batchId = $this->postJson('/api/v1/notifications/batch', ['notifications' => [
        ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello'],
    ]])->json('data.batch_id');
    $notification = Notification::query()->where('batch_id', $batchId)->sole();

    Event::fake([NotificationStatusBroadcast::class]);
    providerResponds();
    deliver($notification);

    Event::assertDispatched(NotificationStatusBroadcast::class, function (NotificationStatusBroadcast $event) use ($notification, $batchId): bool {
        $channels = array_map(static fn (Channel $channel): string => $channel->name, $event->broadcastOn());
        $payload = $event->broadcastWith();

        return $channels === ['notifications', "notifications.{$notification->id}", "batches.{$batchId}"]
            && $event->broadcastAs() === 'notification.status'
            && $payload['id'] === $notification->id
            && $payload['status'] === 'sent'
            && $payload['channel'] === 'sms'
            && $payload['attempts'] === 1;
    });
});

it('announces retries, failures and cancellations too', function (): void {
    Event::fake([NotificationStatusBroadcast::class]);

    providerResponds(503);
    deliver(accept());
    providerResponds(400);
    deliver(accept());
    $cancelled = accept();
    $this->postJson("/api/v1/notifications/{$cancelled->id}/cancel")->assertOk();

    $statuses = Event::dispatched(NotificationStatusBroadcast::class)
        ->map(static fn (array $call): string => $call[0]->broadcastWith()['status'])
        ->all();

    expect($statuses)->toBe(['retrying', 'failed', 'cancelled']);
});

it('publishes on its own queue so it never competes with delivery', function (): void {
    Queue::fake();
    providerResponds();

    deliver(accept());

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
});

it('does not let a broadcasting problem affect the delivery', function (): void {
    $notification = accept();
    providerResponds();
    Event::listen(NotificationStatusBroadcast::class, fn (): never => throw new RuntimeException('websocket server is down'));

    deliver($notification);

    expect($notification->fresh()->status->value)->toBe('sent');
});

it('serves a page that subscribes to the stream', function (): void {
    $this->get('/live')
        ->assertOk()
        ->assertSee('Live notification status')
        ->assertSee('notification-system-key');
});
