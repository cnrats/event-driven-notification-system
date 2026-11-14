<?php

declare(strict_types=1);

use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Delivery\Sweeper;
use App\Modules\Notifications\Domain\NotificationStatus as Status;
use App\Modules\Notifications\Models\Notification;
use App\Support\DbTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('queues a scheduled notification when its time has come', function (): void {
    $notification = accept(['scheduled_at' => now()->addMinutes(10)->toIso8601String(), 'priority' => 'high']);

    expect(resolve(Sweeper::class)->sweep()['due'])->toBe(0);
    Queue::assertNothingPushed();

    $this->travel(11)->minutes();
    $moved = resolve(Sweeper::class)->sweep();

    expect($moved['due'])->toBe(1)
        ->and($notification->fresh())->status->toBe(Status::Queued)->next_attempt_at->toBeNull()->enqueued_at->not->toBeNull();
    Queue::assertPushedOn('sms-high', DeliverNotification::class, fn (DeliverNotification $job): bool => $job->notificationId === $notification->id);
});

it('queues a retry when its backoff is over', function (): void {
    $notification = accept();
    providerResponds(503);
    deliver($notification);
    expect($notification->fresh()->status)->toBe(Status::Retrying);

    expect(resolve(Sweeper::class)->sweep()['due'])->toBe(0);

    $this->travel(3)->seconds();

    expect(resolve(Sweeper::class)->sweep()['due'])->toBe(1)
        ->and($notification->fresh()->status)->toBe(Status::Queued);
});

it('promotes a large scheduled campaign in one sweep', function (): void {
    $this->configure(['sweeper.chunk' => 50]);
    $at = now()->addMinute()->toIso8601String();

    $this->postJson('/api/v1/notifications/batch', ['notifications' => array_fill(0, 120, [
        'channel' => 'email', 'recipient' => 'a@example.com', 'content' => 'Sale', 'scheduled_at' => $at,
    ])])->assertAccepted();

    $this->travel(2)->minutes();

    expect(resolve(Sweeper::class)->sweep()['due'])->toBe(120)
        ->and(Notification::query()->where('status', 'queued')->count())->toBe(120);
    Queue::assertPushed(DeliverNotification::class, 120);
});

it('recovers a notification whose worker died while delivering it', function (): void {
    $notification = accept();
    Notification::query()->whereKey($notification->id)->update([
        'status' => 'processing', 'attempts' => 1, 'locked_until' => DbTime::format(DbTime::now()->addSeconds(30)),
    ]);

    // Lease still valid: the worker may be alive.
    expect(resolve(Sweeper::class)->sweep()['leases'])->toBe(0);

    $this->travel(40)->seconds();

    expect(resolve(Sweeper::class)->sweep()['leases'])->toBe(1)
        ->and($notification->fresh())->status->toBe(Status::Queued)->attempts->toBe(1)->locked_until->toBeNull();
    Queue::assertPushed(DeliverNotification::class, 2);
});

it('enqueues a notification whose push to the queue was never confirmed', function (): void {
    // Committed to the database, but the process died before the Redis push.
    $notification = accept();
    Notification::query()->whereKey($notification->id)->update(['enqueued_at' => null]);

    // Within the grace period the original request may still be pushing.
    expect(resolve(Sweeper::class)->sweep()['unconfirmed'])->toBe(0);

    $this->travel(15)->seconds();

    expect(resolve(Sweeper::class)->sweep()['unconfirmed'])->toBe(1)
        ->and($notification->fresh()->enqueued_at)->not->toBeNull()
        ->and(resolve(Sweeper::class)->sweep()['unconfirmed'])->toBe(0);
});

it('re-enqueues a notification whose job was lost', function (): void {
    // The push was confirmed but the job vanished (Redis lost its data).
    $notification = accept();

    $this->travel(10)->minutes();
    expect(resolve(Sweeper::class)->sweep()['stale'])->toBe(0);

    $this->travel(6)->minutes();
    expect(resolve(Sweeper::class)->sweep()['stale'])->toBe(1)
        ->and(resolve(Sweeper::class)->sweep()['stale'])->toBe(0);

    expect($notification->fresh()->status)->toBe(Status::Queued);
});

it('leaves finished and cancelled notifications alone', function (): void {
    $sent = accept();
    providerResponds();
    deliver($sent);

    $cancelled = accept(['scheduled_at' => now()->addMinute()->toIso8601String()]);
    $this->postJson("/api/v1/notifications/{$cancelled->id}/cancel")->assertOk();

    $this->travel(1)->hours();

    expect(array_sum(resolve(Sweeper::class)->sweep()))->toBe(0)
        ->and($sent->fresh()->status)->toBe(Status::Sent)
        ->and($cancelled->fresh()->status)->toBe(Status::Cancelled);
});

it('runs as a command', function (): void {
    accept(['scheduled_at' => now()->addSecond()->toIso8601String()]);
    $this->travel(5)->seconds();

    $this->artisan('notifications:sweep --once')->assertSuccessful();

    expect(Notification::query()->where('status', 'queued')->count())->toBe(1)
        ->and(Cache::get('sweeper:heartbeat'))->toBeNumeric();
});
