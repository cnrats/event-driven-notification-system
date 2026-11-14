<?php

declare(strict_types=1);

use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('accepts a notification and queues it for delivery', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your code is 1234',
        'priority' => 'high',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.attempts', 0)
        ->assertHeader('Idempotent-Replayed', 'false');

    $id = $response->json('data.id');

    Queue::assertPushedOn('sms-high', DeliverNotification::class, fn (DeliverNotification $job): bool => $job->notificationId === $id);
    expect(Notification::query()->findOrFail($id)->enqueued_at)->not->toBeNull();
});

it('defaults to normal priority', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'email', 'recipient' => 'user@example.com', 'content' => 'Welcome',
    ])->assertCreated()->assertJsonPath('data.priority', 'normal');

    Queue::assertPushedOn('email-normal', DeliverNotification::class);
});

it('rejects invalid input with one error per field', function (array $payload, string $field): void {
    $this->postJson('/api/v1/notifications', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor($field);

    expect(Notification::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'unknown channel' => [['channel' => 'fax', 'recipient' => '+905551234567', 'content' => 'x'], 'channel'],
    'missing recipient' => [['channel' => 'sms', 'content' => 'x'], 'recipient'],
    'sms to an email address' => [['channel' => 'sms', 'recipient' => 'user@example.com', 'content' => 'x'], 'recipient'],
    'email to a phone number' => [['channel' => 'email', 'recipient' => '+905551234567', 'content' => 'x'], 'recipient'],
    'missing content' => [['channel' => 'sms', 'recipient' => '+905551234567'], 'content'],
    'blank content' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => '  '], 'content'],
    'unknown priority' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x', 'priority' => 'urgent'], 'priority'],
    'schedule in the past' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x', 'scheduled_at' => '2020-01-01T00:00:00Z'], 'scheduled_at'],
    'schedule not a date' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x', 'scheduled_at' => 'tomorrow-ish'], 'scheduled_at'],
    'content and template together' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x', 'template' => 'welcome'], 'content'],
]);

it('rejects an SMS that needs too many segments because of non-GSM characters', function (): void {
    // 202 characters are fine in plain ASCII (2 segments) but need 4 UCS-2
    // segments once a Turkish character is involved.
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => str_repeat('ş', 202),
    ])->assertUnprocessable()->assertJsonValidationErrorFor('content');

    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => str_repeat('a', 202),
    ])->assertCreated();
});

it('stores a future notification as scheduled without queueing it', function (): void {
    $at = now()->addHour()->toIso8601String();

    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'push', 'recipient' => 'device-token-123456', 'content' => 'Sale starts', 'scheduled_at' => $at,
    ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

    expect($response->json('data.scheduled_at'))->not->toBeNull()
        ->and($response->json('data.next_attempt_at'))->toBe($response->json('data.scheduled_at'));

    Queue::assertNothingPushed();
});

it('returns the correlation id it was given and stores it on the notification', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x',
    ], ['X-Correlation-ID' => 'order-42-shipped']);

    $response->assertHeader('X-Correlation-ID', 'order-42-shipped')
        ->assertJsonPath('data.correlation_id', 'order-42-shipped');
});

it('generates a correlation id when the caller sends none or a suspicious one', function (?string $given): void {
    $headers = $given === null ? [] : ['X-Correlation-ID' => $given];

    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x',
    ], $headers);

    expect($response->headers->get('X-Correlation-ID'))->toMatch('/^[0-9a-f-]{36}$/');
})->with([null, 'has spaces and "quotes"', str_repeat('a', 200)]);

it('still accepts the notification when the queue is unavailable', function (): void {
    // The row is the source of truth; the sweeper enqueues it later.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('redis is down'));

    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'x',
    ])->assertCreated();

    $notification = Notification::query()->findOrFail($response->json('data.id'));

    expect($notification->status->value)->toBe('queued')
        ->and($notification->enqueued_at)->toBeNull();
});

it('answers with JSON even when the client does not ask for it', function (): void {
    $this->post('/api/v1/notifications', [], ['Accept' => 'text/html'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json');
});
