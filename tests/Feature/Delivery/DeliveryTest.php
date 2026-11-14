<?php

declare(strict_types=1);

use App\Modules\Delivery\DeliveryHandler;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus as Status;
use App\Modules\Notifications\Models\DeliveryAttempt;
use App\Modules\Notifications\Models\Notification;
use App\Support\DbTime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends the notification to the provider in the agreed format', function (): void {
    $notification = accept(['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Your code is 1234']);
    providerResponds(202, messageId: 'provider-msg-9');

    $outcome = deliver($notification);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://provider.test/send'
        && $request->method() === 'POST'
        && $request->data() === ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Your code is 1234']
        // The notification id is the idempotency key: a resend after a lost
        // response is recognisable to the provider.
        && $request->header('Idempotency-Key') === [$notification->id]
        && $request->header('X-Correlation-ID') === ['test-correlation-id']);

    $notification = $notification->fresh();

    expect($outcome->reason)->toBe('accepted')
        ->and($notification->status)->toBe(Status::Sent)
        ->and($notification->provider_message_id)->toBe('provider-msg-9')
        ->and($notification->attempts)->toBe(1)
        ->and($notification->sent_at)->not->toBeNull()
        ->and($notification->locked_until)->toBeNull();

    expect(DeliveryAttempt::query()->where('notification_id', $notification->id)->sole())
        ->outcome->toBe('accepted')
        ->http_status->toBe(202)
        ->attempt->toBe(1);
});

it('schedules a retry with backoff when the provider fails temporarily', function (int $status): void {
    $notification = accept();
    providerResponds($status);
    $before = DbTime::now();

    deliver($notification);

    $notification = $notification->fresh();
    $delay = $before->diffInSeconds($notification->next_attempt_at);

    // First retry: between 1 and 2 seconds (base 2 s, equal jitter).
    expect($notification->status)->toBe(Status::Retrying)
        ->and($notification->attempts)->toBe(1)
        ->and($delay)->toBeGreaterThanOrEqual(0.9)->toBeLessThanOrEqual(2.5)
        ->and($notification->last_error)->toContain("HTTP {$status}")
        ->and($notification->locked_until)->toBeNull();
})->with([500, 502, 503, 504, 408]);

it('treats a timeout or connection error as retryable', function (): void {
    $notification = accept();
    Http::fake(['provider.test/*' => fn (): never => throw new ConnectionException('cURL error 28: Operation timed out')]);

    deliver($notification);

    expect($notification->fresh())
        ->status->toBe(Status::Retrying)
        ->last_error->toContain('connection: cURL error 28');
});

it('fails immediately when the provider rejects the message for good', function (int $status): void {
    $notification = accept();
    providerResponds($status);

    deliver($notification);

    expect($notification->fresh())
        ->status->toBe(Status::Failed)
        ->attempts->toBe(1)
        ->failed_at->not->toBeNull();
})->with([400, 401, 403, 404, 422]);

it('gives up after the maximum number of delivery attempts', function (): void {
    $notification = accept();
    providerResponds(503);

    foreach (range(1, 5) as $attempt) {
        deliver($notification);

        if ($attempt < 5) {
            // What the sweeper does when the retry is due.
            Notification::query()->whereKey($notification->id)->update(['status' => 'queued', 'next_attempt_at' => null]);
        }
    }

    $notification = $notification->fresh();

    expect($notification->status)->toBe(Status::Failed)
        ->and($notification->attempts)->toBe(5)
        ->and($notification->last_error)->toStartWith('gave up after 5 attempts')
        ->and(DeliveryAttempt::query()->where('notification_id', $notification->id)->count())->toBe(5);
});

it('does not count a 429 as a failed attempt and pauses the whole channel', function (): void {
    $throttled = accept(['content' => 'first']);
    $next = accept(['content' => 'second']);
    providerResponds(429, ['Retry-After' => '7']);

    deliver($throttled);

    $throttled = $throttled->fresh();
    expect($throttled->status)->toBe(Status::Retrying)
        ->and($throttled->attempts)->toBe(0)
        ->and(DbTime::now()->diffInSeconds($throttled->next_attempt_at))->toBeGreaterThan(6)->toBeLessThanOrEqual(7.1);

    // Every other job on the channel now waits without calling the provider.
    Http::fake();
    $outcome = deliver($next);

    Http::assertNothingSent();
    expect($outcome->reason)->toBe('throttled')
        ->and($outcome->releaseAfterSeconds)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(7)
        ->and($next->fresh()->status)->toBe(Status::Queued)
        ->and($next->fresh()->attempts)->toBe(0);

    // Other channels are not affected.
    expect(resolve(ChannelRateLimiter::class)->reserve(Channel::Email)->granted)->toBeTrue();
});

it('delivers once when the same job runs twice', function (): void {
    $notification = accept();
    providerResponds();

    deliver($notification);
    $second = deliver($notification);

    Http::assertSentCount(1);
    expect($second->reason)->toBe('status_sent')
        ->and($notification->fresh()->attempts)->toBe(1);
});

it('lets exactly one of two workers holding the same job deliver', function (): void {
    // Both workers have loaded the row as "queued" before either claimed it.
    $notification = accept();
    $staleCopy = Notification::query()->findOrFail($notification->id);
    providerResponds();

    $lifecycle = resolve(NotificationLifecycle::class);

    expect($lifecycle->claim($notification, DbTime::now(), 30))->toBeTrue()
        ->and($lifecycle->claim($staleCopy, DbTime::now(), 30))->toBeFalse();
});

it('puts the job back while another worker holds the lease', function (): void {
    $notification = accept();
    Notification::query()->whereKey($notification->id)->update([
        'status' => 'processing',
        'attempts' => 1,
        'locked_until' => DbTime::format(DbTime::now()->addSeconds(20)),
    ]);
    Http::fake();

    $outcome = deliver($notification);

    Http::assertNothingSent();
    expect($outcome->reason)->toBe('lease_held')
        ->and($outcome->releaseAfterSeconds)->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(22);
});

it('takes over a notification whose worker died mid-delivery', function (): void {
    // The first worker claimed the row, sent the request and crashed before
    // recording the result. Its lease has run out.
    $notification = accept();
    Notification::query()->whereKey($notification->id)->update([
        'status' => 'processing',
        'attempts' => 1,
        'locked_until' => DbTime::format(DbTime::now()->subSecond()),
    ]);
    providerResponds();

    deliver($notification);

    // Resent with the same idempotency key, so the provider can deduplicate.
    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === [$notification->id]);
    expect($notification->fresh())->status->toBe(Status::Sent)->attempts->toBe(2);
});

it('fails a notification that could not be delivered within its time to live', function (): void {
    $notification = accept();
    Http::fake();

    $this->travel(25)->hours();
    $outcome = deliver($notification);

    Http::assertNothingSent();
    expect($outcome->reason)->toBe('expired')
        ->and($notification->fresh())->status->toBe(Status::Failed)->last_error->toStartWith('expired');
});

it('ignores a job whose notification no longer exists', function (): void {
    expect(resolve(DeliveryHandler::class)->deliver('01938c2e-7b1a-7c3d-9e4f-000000000000')->reason)->toBe('missing');
});

it('marks the notification failed when the job itself is given up on', function (): void {
    $notification = accept();

    resolve(DeliveryHandler::class)->abandon($notification->id, new RuntimeException('boom'));

    expect($notification->fresh())->status->toBe(Status::Failed)->last_error->toBe('job failed: RuntimeException: boom');
});

it('never overwrites a sent notification when a late job failure arrives', function (): void {
    $notification = accept();
    providerResponds();
    deliver($notification);

    resolve(DeliveryHandler::class)->abandon($notification->id, new RuntimeException('late'));

    expect($notification->fresh()->status)->toBe(Status::Sent);
});
