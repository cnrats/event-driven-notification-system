<?php

declare(strict_types=1);

use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Notifications\Application\CreateNotifications;
use App\Modules\Notifications\Application\RequestMeta;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationBatch;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->payload = ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Order shipped'];
});

it('creates once and replays the original for the same Idempotency-Key', function (): void {
    $first = $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'order-42'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false');

    $second = $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'order-42'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Notification::query()->count())->toBe(1);

    Queue::assertPushed(DeliverNotification::class, 1);
});

it('rejects a key that is reused with a different body', function (): void {
    $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'order-42'])->assertCreated();

    $this->postJson('/api/v1/notifications', ['content' => 'Something else'] + $this->payload, ['Idempotency-Key' => 'order-42'])
        ->assertConflict()
        ->assertJsonPath('message', 'The idempotency key "order-42" was already used with a different request body.');

    expect(Notification::query()->count())->toBe(1);
});

it('accepts the key in the body as well', function (): void {
    $payload = $this->payload + ['idempotency_key' => 'order-43'];

    $first = $this->postJson('/api/v1/notifications', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/notifications', $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
});

it('treats keys as case-sensitive', function (): void {
    $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'Order-42'])->assertCreated();
    $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'order-42'])->assertCreated();

    expect(Notification::query()->count())->toBe(2);
});

it('answers as a replay when the unique index catches a concurrent duplicate', function (): void {
    // Simulates the race: this request passed the "does it exist?" check
    // just before another request with the same key committed.
    $existing = accept($this->payload + ['idempotency_key' => 'race-key']);

    $created = resolve(CreateNotifications::class)->single(
        $this->payload,
        'race-key',
        new RequestMeta,
    );

    expect($created->replayed)->toBeTrue()
        ->and($created->notification->id)->toBe($existing->id);
});

it('replays a whole batch for the same Idempotency-Key', function (): void {
    $body = ['notifications' => [$this->payload, ['channel' => 'email', 'recipient' => 'a@example.com', 'content' => 'Hi']]];

    $first = $this->postJson('/api/v1/notifications/batch', $body, ['Idempotency-Key' => 'campaign-7'])->assertAccepted();
    $second = $this->postJson('/api/v1/notifications/batch', $body, ['Idempotency-Key' => 'campaign-7'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.batch_id'))->toBe($first->json('data.batch_id'))
        ->and($second->json('data.notifications'))->toBe($first->json('data.notifications'))
        ->and(NotificationBatch::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(2);

    Queue::assertPushed(DeliverNotification::class, 2);
});

it('rejects a batch key reused with different items', function (): void {
    $this->postJson('/api/v1/notifications/batch', ['notifications' => [$this->payload]], ['Idempotency-Key' => 'campaign-7'])
        ->assertAccepted();

    $this->postJson('/api/v1/notifications/batch', ['notifications' => [$this->payload, $this->payload]], ['Idempotency-Key' => 'campaign-7'])
        ->assertConflict();
});

it('does not create a batch item again when its own key already exists', function (): void {
    $existing = $this->postJson('/api/v1/notifications', $this->payload, ['Idempotency-Key' => 'item-1'])->json('data.id');

    $response = $this->postJson('/api/v1/notifications/batch', ['notifications' => [
        $this->payload + ['idempotency_key' => 'item-1'],
        $this->payload + ['idempotency_key' => 'item-2'],
    ]])->assertAccepted();

    $response->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.duplicates', 1)
        ->assertJsonPath('data.notifications.0.id', $existing)
        ->assertJsonPath('data.notifications.0.duplicate', true)
        ->assertJsonPath('data.notifications.1.duplicate', false);

    expect(Notification::query()->count())->toBe(2);
});

it('rejects a batch that uses the same item key twice', function (): void {
    $this->postJson('/api/v1/notifications/batch', ['notifications' => [
        $this->payload + ['idempotency_key' => 'same'],
        $this->payload + ['idempotency_key' => 'same'],
    ]])->assertUnprocessable()->assertJsonValidationErrorFor('notifications.1.idempotency_key');

    expect(Notification::query()->count())->toBe(0);
});
