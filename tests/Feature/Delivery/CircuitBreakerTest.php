<?php

declare(strict_types=1);

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus as Status;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    $this->configure(['circuit_breaker.failure_threshold' => 3, 'circuit_breaker.cooldown_seconds' => 15]);
    $this->breaker = resolve(ChannelCircuitBreaker::class);
});

/**
 * Let the cooldown pass without waiting for it.
 */
function endCooldown(Channel $channel): void
{
    Redis::connection()->command('del', ["circuit:{$channel->value}:open"]);
}

it('opens after consecutive failures and rejects further calls', function (): void {
    expect($this->breaker->recordFailure(Channel::Sms, false))->toBeFalse()
        ->and($this->breaker->recordFailure(Channel::Sms, false))->toBeFalse()
        ->and($this->breaker->allow(Channel::Sms)->allowed)->toBeTrue()
        ->and($this->breaker->recordFailure(Channel::Sms, false))->toBeTrue();

    $decision = $this->breaker->allow(Channel::Sms);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->retryAfterSeconds)->toBeGreaterThanOrEqual(14)->toBeLessThanOrEqual(15)
        ->and($this->breaker->state(Channel::Sms))->toBe('open');
});

it('counts only consecutive failures', function (): void {
    $this->breaker->recordFailure(Channel::Sms, false);
    $this->breaker->recordFailure(Channel::Sms, false);
    $this->breaker->recordSuccess(Channel::Sms, false);
    $this->breaker->recordFailure(Channel::Sms, false);
    $this->breaker->recordFailure(Channel::Sms, false);

    expect($this->breaker->state(Channel::Sms))->toBe('closed');
});

it('keeps channels independent', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure(Channel::Sms, false);
    }

    expect($this->breaker->allow(Channel::Sms)->allowed)->toBeFalse()
        ->and($this->breaker->allow(Channel::Email)->allowed)->toBeTrue();
});

it('lets exactly one probe through after the cooldown', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure(Channel::Sms, false);
    }
    endCooldown(Channel::Sms);

    $first = $this->breaker->allow(Channel::Sms);
    $second = $this->breaker->allow(Channel::Sms);

    expect($this->breaker->state(Channel::Sms))->toBe('half_open')
        ->and($first->allowed)->toBeTrue()
        ->and($first->probe)->toBeTrue()
        ->and($second->allowed)->toBeFalse();
});

it('closes when the probe succeeds', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure(Channel::Sms, false);
    }
    endCooldown(Channel::Sms);
    $this->breaker->allow(Channel::Sms);

    $this->breaker->recordSuccess(Channel::Sms, true);

    expect($this->breaker->state(Channel::Sms))->toBe('closed')
        ->and($this->breaker->allow(Channel::Sms)->probe)->toBeFalse();
});

it('opens again when the probe fails', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure(Channel::Sms, false);
    }
    endCooldown(Channel::Sms);
    $this->breaker->allow(Channel::Sms);

    expect($this->breaker->recordFailure(Channel::Sms, true))->toBeTrue()
        ->and($this->breaker->state(Channel::Sms))->toBe('open');
});

it('gives the probe to the next job when the first one did not reach the provider', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure(Channel::Sms, false);
    }
    endCooldown(Channel::Sms);

    $this->breaker->allow(Channel::Sms);
    $this->breaker->abandonProbe(Channel::Sms);

    expect($this->breaker->allow(Channel::Sms)->probe)->toBeTrue();
});

it('spends no delivery attempts while the provider is down', function (): void {
    // Three notifications fail and trip the circuit...
    providerResponds(503);
    foreach (range(1, 3) as $ignored) {
        deliver(accept());
    }

    // ...so the rest of the backlog waits instead of burning its attempts.
    $waiting = accept(['content' => 'still here']);
    Http::fake();
    $outcome = deliver($waiting);

    Http::assertNothingSent();
    expect($outcome->reason)->toBe('circuit_open')
        ->and($outcome->releaseAfterSeconds)->toBeGreaterThanOrEqual(14)
        ->and($waiting->fresh())->status->toBe(Status::Queued)->attempts->toBe(0);
});

it('resumes delivery once the provider is back', function (): void {
    providerResponds(503);
    foreach (range(1, 3) as $ignored) {
        deliver(accept());
    }
    endCooldown(Channel::Sms);

    $notification = accept();
    providerResponds(202);
    deliver($notification);

    expect($notification->fresh()->status)->toBe(Status::Sent)
        ->and($this->breaker->state(Channel::Sms))->toBe('closed');
});

it('does not open on rejections or throttling, which say nothing about provider health', function (int $status): void {
    providerResponds($status, ['Retry-After' => '1']);
    foreach (range(1, 5) as $ignored) {
        // Clear the pause a 429 puts on the channel so every call reaches the provider.
        resolve(ChannelRateLimiter::class)->reset(Channel::Sms);
        deliver(accept());
    }

    expect($this->breaker->state(Channel::Sms))->toBe('closed');
})->with([400, 429]);
