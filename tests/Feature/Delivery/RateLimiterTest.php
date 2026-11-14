<?php

declare(strict_types=1);

use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Domain\Channel;

/**
 * Largest number of slots that fall into any one-second window [t, t + 1 s).
 *
 * @param  list<int>  $slots  slot times in microseconds, ascending
 */
function maxPerSecond(array $slots): int
{
    $max = 0;
    $start = 0;

    foreach ($slots as $end => $slot) {
        while ($slot - $slots[$start] >= 1_000_000) {
            $start++;
        }
        $max = max($max, $end - $start + 1);
    }

    return $max;
}

beforeEach(function (): void {
    // Let the limiter hand out slots far into the future so a burst of
    // reservations can be inspected without real waiting.
    $this->configure(['rate_limit.max_block_ms' => 60_000]);
    $this->limiter = resolve(ChannelRateLimiter::class);
});

it('never hands out more than 100 slots in any one-second window', function (): void {
    $slots = [];
    $start = (int) (microtime(true) * 1_000_000);

    // 500 workers ask at the same instant: the worst possible burst.
    foreach (range(1, 500) as $ignored) {
        $reservation = $this->limiter->reserve(Channel::Sms);
        expect($reservation->granted)->toBeTrue();
        $slots[] = (int) (microtime(true) * 1_000_000) + $reservation->waitMicros;
    }

    sort($slots);

    expect(maxPerSecond($slots))->toBeLessThanOrEqual(100)
        // ...and the limiter is not needlessly slow: 500 slots take about 5 s.
        ->and(($slots[499] - $start) / 1_000_000)->toBeGreaterThan(5.0)->toBeLessThan(5.6);
});

it('spaces slots evenly instead of allowing a burst at the window edge', function (): void {
    $waits = array_map(fn (): int => $this->limiter->reserve(Channel::Sms)->waitMicros, range(1, 50));
    $interval = $this->limiter->intervalMicros(Channel::Sms);

    foreach (range(1, 49) as $i) {
        // Each reservation waits roughly one interval longer than the previous one.
        expect($waits[$i] - $waits[$i - 1])->toBeGreaterThan($interval * 0.5)->toBeLessThanOrEqual($interval);
    }
});

it('applies the safety margin to the slot interval', function (): void {
    // 100/s would be 10 000 us; with the 5 % margin a slot is 10 500 us.
    expect($this->limiter->intervalMicros(Channel::Sms))->toBe(10_500);

    $this->configure(['channels.sms.rate_per_second' => 10, 'rate_limit.safety_margin' => 0.0]);
    expect(resolve(ChannelRateLimiter::class)->intervalMicros(Channel::Sms))->toBe(100_000);
});

it('keeps a separate budget per channel', function (): void {
    foreach (range(1, 100) as $ignored) {
        $this->limiter->reserve(Channel::Sms);
    }

    expect($this->limiter->reserve(Channel::Sms)->waitMicros)->toBeGreaterThan(900_000)
        ->and($this->limiter->reserve(Channel::Email)->waitMicros)->toBe(0);
});

it('hands out no slots while the channel is paused', function (): void {
    $this->configure(['rate_limit.max_block_ms' => 1500]);
    $limiter = resolve(ChannelRateLimiter::class);

    $limiter->pause(Channel::Sms, 5);
    $reservation = $limiter->reserve(Channel::Sms);

    expect($reservation->granted)->toBeFalse()
        ->and($reservation->waitSeconds())->toBeGreaterThan(4.5)->toBeLessThanOrEqual(5.0);
});

it('does not consume a slot when it refuses to reserve', function (): void {
    $this->configure(['rate_limit.max_block_ms' => 1500]);
    $limiter = resolve(ChannelRateLimiter::class);
    $limiter->pause(Channel::Sms, 5);

    $first = $limiter->reserve(Channel::Sms)->waitMicros;
    $second = $limiter->reserve(Channel::Sms)->waitMicros;

    // The wait shrinks with time; it would grow if refusals took slots.
    expect($second)->toBeLessThanOrEqual($first);
});

it('never shortens an existing pause', function (): void {
    $this->limiter->pause(Channel::Sms, 10);
    $this->limiter->pause(Channel::Sms, 1);

    expect($this->limiter->reserve(Channel::Sms)->waitSeconds())->toBeGreaterThan(9);
});
