<?php

declare(strict_types=1);

use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function batchOf(int $count): array
{
    $channels = [
        ['channel' => 'sms', 'recipient' => '+905551234567'],
        ['channel' => 'email', 'recipient' => 'user@example.com'],
        ['channel' => 'push', 'recipient' => 'device-token-123456'],
    ];
    $priorities = ['high', 'normal', 'low'];
    $items = [];

    for ($i = 0; $i < $count; $i++) {
        $items[] = $channels[$i % 3] + ['content' => "Message {$i}", 'priority' => $priorities[intdiv($i, 3) % 3]];
    }

    return $items;
}

it('accepts 1000 notifications in one request', function (): void {
    $response = $this->postJson('/api/v1/notifications/batch', ['notifications' => batchOf(1000)])
        ->assertAccepted()
        ->assertJsonPath('data.created', 1000)
        ->assertJsonPath('data.duplicates', 0)
        ->assertJsonCount(1000, 'data.notifications');

    $batchId = $response->json('data.batch_id');

    expect(Notification::query()->where('batch_id', $batchId)->count())->toBe(1000)
        ->and(Notification::query()->where('batch_id', $batchId)->whereNull('enqueued_at')->count())->toBe(0);

    Queue::assertPushed(DeliverNotification::class, 1000);
    Queue::assertPushedOn('sms-high', DeliverNotification::class);
    Queue::assertPushedOn('email-low', DeliverNotification::class);
    Queue::assertPushedOn('push-normal', DeliverNotification::class);
});

it('keeps the order of the request in the response', function (): void {
    $response = $this->postJson('/api/v1/notifications/batch', ['notifications' => batchOf(5)])->assertAccepted();

    expect(array_column($response->json('data.notifications'), 'index'))->toBe([0, 1, 2, 3, 4]);

    $third = Notification::query()->findOrFail($response->json('data.notifications.2.id'));
    expect($third->content)->toBe('Message 2')->and($third->batch_index)->toBe(2);
});

it('rejects more than 1000 notifications', function (): void {
    $this->postJson('/api/v1/notifications/batch', ['notifications' => batchOf(1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('notifications');
});

it('rejects an empty or malformed batch', function (mixed $notifications): void {
    $this->postJson('/api/v1/notifications/batch', ['notifications' => $notifications])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('notifications');
})->with([[[]], ['not-an-array'], [null]]);

it('stores nothing when one item is invalid and reports where', function (): void {
    $items = batchOf(10);
    $items[7]['recipient'] = 'not-a-phone';
    $items[9] = 'not-an-object';

    $this->postJson('/api/v1/notifications/batch', ['notifications' => $items])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('notifications.7.recipient')
        ->assertJsonValidationErrorFor('notifications.9');

    expect(Notification::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('summarises a batch by status', function (): void {
    $batchId = $this->postJson('/api/v1/notifications/batch', ['notifications' => batchOf(6)])->json('data.batch_id');

    $this->getJson("/api/v1/batches/{$batchId}")
        ->assertOk()
        ->assertJsonPath('data.total', 6)
        ->assertJsonPath('data.statuses.queued', 6)
        ->assertJsonPath('data.statuses.sent', 0)
        ->assertJsonPath('data.finished', false);
});

it('cancels everything in a batch that has not been sent', function (): void {
    $batchId = $this->postJson('/api/v1/notifications/batch', ['notifications' => batchOf(6)])->json('data.batch_id');

    $this->postJson("/api/v1/batches/{$batchId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.cancelled', 6)
        ->assertJsonPath('data.statuses.cancelled', 6)
        ->assertJsonPath('data.finished', true);
});

it('returns 404 for an unknown batch', function (): void {
    $this->getJson('/api/v1/batches/01938c2e-7b1a-7c3d-9e4f-000000000000')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
});
