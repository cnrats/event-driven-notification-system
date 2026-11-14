<?php

declare(strict_types=1);

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Jobs\DeliverNotification;
use App\Modules\Delivery\Queue\QueueDispatcher;
use App\Modules\Notifications\Contracts\QueueTicket;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus as Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Fixtures\RateLimitedJobWithFixedTries;

/*
 * Why delivery attempts are not counted with Laravel's `tries`.
 *
 * Laravel increments a job's attempt counter every time the job is popped,
 * also when the job only puts itself back because it has to wait. Under a
 * burst a job may wait many times, so a fixed `tries` fails notifications
 * that the provider never rejected. These tests run a real worker against
 * the real Redis queue to show the problem and that this system avoids it.
 */

/**
 * Run `queue:work --once`: the next job on the queue is processed exactly as
 * in production, including how failures are recorded in failed_jobs.
 *
 * @param  int  $afterSeconds  time that passes before the worker looks again
 */
function workOnce(string $queue, int $afterSeconds = 2): void
{
    test()->travel($afterSeconds)->seconds();

    test()->artisan('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--sleep' => 0]);
}

it('shows the pitfall: a job that only waits for the rate limit ends up failed', function (): void {
    dispatch((new RateLimitedJobWithFixedTries)->onQueue('demo'));

    foreach (range(1, 4) as $ignored) {
        workOnce('demo');
    }

    // The job never threw and nothing was wrong with it, yet Laravel gave up.
    expect(Queue::size('demo'))->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('MaxAttemptsExceededException');
});

it('delivers a notification no matter how often its job had to wait', function (): void {
    // Provider outage that lasts for days: the circuit stays open.
    $this->configure([
        'circuit_breaker.failure_threshold' => 1,
        'circuit_breaker.cooldown_seconds' => 3600,
        'delivery.ttl_seconds' => 365 * 86_400,
    ]);
    $notification = accept(fakeQueue: false);
    resolve(ChannelCircuitBreaker::class)->recordFailure(Channel::Sms, false);
    Http::fake();

    // The job is popped and put back 25 times while the provider is down.
    foreach (range(1, 25) as $ignored) {
        workOnce('sms-normal', afterSeconds: 3601);
    }

    $attemptsSeenByLaravel = json_decode((string) Redis::connection()->command('zrange', ['queues:sms-normal:delayed', 0, 0])[0], true)['attempts'];

    Http::assertNothingSent();
    expect($attemptsSeenByLaravel)->toBe(25)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($notification->fresh())->status->toBe(Status::Queued)->attempts->toBe(0);

    // It is delivered on the first try once the provider is back.
    resolve(ChannelCircuitBreaker::class)->reset(Channel::Sms);
    providerResponds(202);
    workOnce('sms-normal', afterSeconds: 3601);

    expect($notification->fresh())->status->toBe(Status::Sent)->attempts->toBe(1)
        ->and(Queue::size('sms-normal'))->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('tells the queue to allow unlimited pops and to give up only on real errors', function (): void {
    $notification = accept(fakeQueue: false);

    $payload = json_decode((string) Redis::connection()->command('lindex', ['queues:sms-normal', 0]), true);

    expect($payload['maxTries'])->toBe(0)
        ->and($payload['maxExceptions'])->toBe(3)
        ->and($payload['timeout'])->toBe(20)
        ->and($payload['backoff'])->toBe('5,30')
        ->and($payload['data']['commandName'])->toBe(DeliverNotification::class)
        ->and(unserialize($payload['data']['command'])->notificationId)->toBe($notification->id);
});

it('fails the notification when its job keeps crashing', function (): void {
    $notification = accept(fakeQueue: false);
    // Not a provider failure: the code itself throws, as a bug would.
    Http::fake(['provider.test/*' => fn (): never => throw new LogicException('bug in the handler')]);

    // Three uncaught exceptions are allowed (MaxExceptions). Each run waits
    // out the backoff and the lease the crashed attempt left behind.
    foreach (range(1, 3) as $ignored) {
        workOnce('sms-normal', afterSeconds: 40);
    }

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('bug in the handler')
        ->and(Queue::size('sms-normal'))->toBe(0)
        ->and($notification->fresh())->status->toBe(Status::Failed)->last_error->toContain('bug in the handler');
});

it('pushes each notification to the queue of its channel and priority', function (): void {
    $high = accept(['priority' => 'high'], fakeQueue: false);
    $low = accept(['channel' => 'email', 'recipient' => 'a@example.com', 'priority' => 'low'], fakeQueue: false);
    Redis::connection()->command('flushdb');

    resolve(QueueDispatcher::class)->dispatch([
        new QueueTicket($high->id, $high->channel, $high->priority),
        new QueueTicket($low->id, $low->channel, $low->priority),
    ]);

    expect(Queue::size('sms-high'))->toBe(1)
        ->and(Queue::size('email-low'))->toBe(1)
        ->and(Queue::size('sms-normal'))->toBe(0);
});
