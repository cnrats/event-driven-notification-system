<?php

declare(strict_types=1);

use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Http;

it('shows a notification with its delivery attempts', function (): void {
    $notification = accept();
    providerResponds(503);
    deliver($notification);

    $this->getJson("/api/v1/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'retrying')
        ->assertJsonPath('data.attempts', 1)
        ->assertJsonCount(1, 'data.delivery_attempts')
        ->assertJsonPath('data.delivery_attempts.0.outcome', 'retryable')
        ->assertJsonPath('data.delivery_attempts.0.http_status', 503);
});

it('returns 404 as JSON for an unknown notification', function (): void {
    $this->getJson('/api/v1/notifications/01938c2e-7b1a-7c3d-9e4f-000000000000')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
});

it('filters the list by status, channel, priority and batch', function (): void {
    $sms = accept(['channel' => 'sms', 'priority' => 'high']);
    $email = accept(['channel' => 'email', 'recipient' => 'a@example.com', 'priority' => 'low']);
    providerResponds();
    deliver($sms);

    $this->getJson('/api/v1/notifications?status=sent')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sms->id);
    $this->getJson('/api/v1/notifications?channel=email')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $email->id);
    $this->getJson('/api/v1/notifications?priority=low')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $email->id);
    $this->getJson('/api/v1/notifications?status=queued&channel=sms')->assertJsonCount(0, 'data');
});

it('filters the list by creation time', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $old = accept();
    $this->travelTo('2026-11-10 10:00:00');
    $new = accept();
    $this->travelBack();

    $this->getJson('/api/v1/notifications?from=2026-11-05T00:00:00Z')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new->id);
    $this->getJson('/api/v1/notifications?to=2026-11-05T00:00:00Z')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/v1/notifications?from=2026-11-12T00:00:00Z&to=2026-11-01T00:00:00Z')->assertUnprocessable();
});

it('paginates with a cursor, newest first', function (): void {
    foreach (range(1, 5) as $i) {
        $this->travelTo("2026-11-01 10:00:0{$i}");
        accept(['content' => "Message {$i}"]);
    }
    $this->travelBack();

    $first = $this->getJson('/api/v1/notifications?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($first->json('data'), 'content'))->toBe(['Message 5', 'Message 4']);

    $second = $this->getJson('/api/v1/notifications?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertJsonCount(2, 'data');
    expect(array_column($second->json('data'), 'content'))->toBe(['Message 3', 'Message 2']);
});

it('rejects unknown filter values', function (string $query): void {
    $this->getJson("/api/v1/notifications?{$query}")->assertUnprocessable();
})->with(['status=done', 'channel=fax', 'per_page=1000', 'batch_id=not-a-uuid']);

it('cancels a queued notification', function (): void {
    $notification = accept();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($notification->fresh()->cancelled_at)->not->toBeNull();
});

it('never sends a notification that was cancelled while its job was waiting', function (): void {
    $notification = accept();
    Http::fake();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")->assertOk();
    $outcome = deliver($notification);

    Http::assertNothingSent();
    expect($outcome->reason)->toBe('status_cancelled')
        ->and($notification->fresh()->status)->toBe(NotificationStatus::Cancelled);
});

it('refuses to cancel a notification that was already sent', function (): void {
    $notification = accept();
    providerResponds();
    deliver($notification);

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('status', 'sent');
});

it('puts a failed notification back in the queue on manual retry', function (): void {
    $notification = accept();
    providerResponds(400);
    deliver($notification);
    expect($notification->fresh()->status)->toBe(NotificationStatus::Failed);

    $this->postJson("/api/v1/notifications/{$notification->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.attempts', 0);

    $this->postJson("/api/v1/notifications/{$notification->id}/retry")->assertConflict();
    expect(Notification::query()->findOrFail($notification->id)->failed_at)->toBeNull();
});
