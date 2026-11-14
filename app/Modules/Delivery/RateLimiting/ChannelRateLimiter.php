<?php

declare(strict_types=1);

namespace App\Modules\Delivery\RateLimiting;

use App\Modules\Notifications\Domain\Channel;
use App\Support\NotificationsConfig;
use Illuminate\Redis\Connections\Connection;
use RuntimeException;

/**
 * "At most N messages per second per channel", enforced across all workers.
 *
 * The limiter hands out evenly spaced time slots (GCRA with no burst): for
 * 100/s there is one slot every 10 ms. Redis stores a single value per
 * channel, the time of the next free slot. A worker atomically takes that
 * slot, moves the pointer forward by one interval, and sleeps until its slot
 * arrives. Consequences:
 *
 *  - The limit is global. All worker processes share the pointer, so adding
 *    workers never raises the rate.
 *  - No window edge. A fixed one-second counter lets 100 messages through at
 *    0.99 s and another 100 at 1.01 s; evenly spaced slots cannot do that.
 *  - A waiting job is not put back on the queue, so it costs no queue attempt
 *    and causes no pop/release churn.
 *
 * Redis' own clock (TIME) is used inside the script, so workers with slightly
 * different clocks still agree on the slots.
 */
final readonly class ChannelRateLimiter
{
    /**
     * KEYS[1] next free slot (microseconds since epoch)
     * ARGV[1] slot interval in microseconds
     * ARGV[2] longest wait the caller accepts, in microseconds
     * Returns {granted, wait in microseconds}.
     */
    private const string RESERVE = <<<'LUA'
        local time = redis.call('TIME')
        local now = time[1] * 1000000 + time[2]
        local slot = tonumber(redis.call('GET', KEYS[1]) or '0')
        if slot < now then slot = now end
        local wait = slot - now
        if wait > tonumber(ARGV[2]) then
            return {0, string.format('%.0f', wait)}
        end
        redis.call('SET', KEYS[1], string.format('%.0f', slot + tonumber(ARGV[1])), 'PX', 3600000)
        return {1, string.format('%.0f', wait)}
        LUA;

    /**
     * KEYS[1] next free slot
     * ARGV[1] microseconds from now before which no slot may be handed out
     */
    private const string PAUSE = <<<'LUA'
        local time = redis.call('TIME')
        local resume = time[1] * 1000000 + time[2] + tonumber(ARGV[1])
        local slot = tonumber(redis.call('GET', KEYS[1]) or '0')
        if resume > slot then
            redis.call('SET', KEYS[1], string.format('%.0f', resume), 'PX', 3600000)
        end
        return 1
        LUA;

    public function __construct(
        private Connection $redis,
        private NotificationsConfig $config,
    ) {}

    /**
     * Take the next slot for the channel. The caller must wait waitMicros
     * before sending. If that wait exceeds the configured maximum (the
     * channel is paused), nothing is reserved and granted is false.
     */
    public function reserve(Channel $channel): Reservation
    {
        $reply = $this->redis->command('eval', [
            self::RESERVE,
            [$this->key($channel), $this->intervalMicros($channel), $this->config->rateMaxBlockMs * 1000],
            1,
        ]);

        if (! is_array($reply) || count($reply) !== 2) {
            throw new RuntimeException('Unexpected reply from the rate limiter script.');
        }

        return new Reservation(
            granted: is_numeric($reply[0]) && (int) $reply[0] === 1,
            waitMicros: is_numeric($reply[1]) ? (int) $reply[1] : 0,
        );
    }

    /**
     * Hand out no slots on this channel for the given time. Used when the
     * provider answers 429 with Retry-After: every worker backs off at once,
     * without each of them having to hit the provider to find out.
     */
    public function pause(Channel $channel, float $seconds): void
    {
        $this->redis->command('eval', [
            self::PAUSE,
            [$this->key($channel), (int) round($seconds * 1_000_000)],
            1,
        ]);
    }

    /**
     * Distance between two slots. Slightly wider than 1/rate: the limit is a
     * ceiling at the provider, and a few milliseconds of network jitter must
     * not squeeze 101 arrivals into one second there.
     */
    public function intervalMicros(Channel $channel): int
    {
        $rate = max(1, $this->config->ratePerSecond($channel));

        return (int) ceil(1_000_000 / $rate * (1 + $this->config->rateSafetyMargin));
    }

    public function reset(Channel $channel): void
    {
        $this->redis->command('del', [$this->key($channel)]);
    }

    private function key(Channel $channel): string
    {
        return "ratelimit:{$channel->value}:next_slot";
    }
}
