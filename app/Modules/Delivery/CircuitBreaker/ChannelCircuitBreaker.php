<?php

declare(strict_types=1);

namespace App\Modules\Delivery\CircuitBreaker;

use App\Modules\Notifications\Domain\Channel;
use App\Support\NotificationsConfig;
use Illuminate\Redis\Connections\Connection;

/**
 * Stops calling a provider that is clearly down.
 *
 * Without it, an outage makes every queued notification burn its delivery
 * attempts against a dead endpoint and end up failed, although nothing was
 * wrong with the notifications. With it, after `failure_threshold`
 * consecutive failures on a channel the circuit opens: jobs are put back
 * without a delivery attempt until the cooldown passes. Then one job is let
 * through as a probe (half-open); success closes the circuit, failure opens
 * it for another cooldown.
 *
 * State is per channel and lives in Redis, so all workers see the same
 * circuit. Only failures that indicate provider health count (5xx, timeouts,
 * connection errors); a 4xx is about the message and a 429 is handled by
 * pausing the rate limiter.
 */
final readonly class ChannelCircuitBreaker
{
    public function __construct(
        private Connection $redis,
        private NotificationsConfig $config,
    ) {}

    public function allow(Channel $channel): CircuitDecision
    {
        $remainingMs = $this->redis->command('pttl', [$this->key($channel, 'open')]);

        if (is_int($remainingMs) && $remainingMs > 0) {
            return CircuitDecision::reject((int) ceil($remainingMs / 1000));
        }

        if (! $this->tripped($channel)) {
            return CircuitDecision::allow();
        }

        // Half-open: exactly one worker gets to try. The lock expires on its
        // own, so a worker that dies holding it cannot wedge the circuit.
        $acquired = $this->redis->command('set', [
            $this->key($channel, 'probe'),
            '1',
            ['nx', 'ex' => $this->config->circuitProbeLockSeconds],
        ]);

        return $acquired === true ? CircuitDecision::probe() : CircuitDecision::reject(1);
    }

    public function recordSuccess(Channel $channel, bool $probe): void
    {
        if ($probe) {
            $this->redis->command('del', [
                $this->key($channel, 'tripped'),
                $this->key($channel, 'probe'),
                $this->key($channel, 'failures'),
            ]);

            return;
        }

        $this->redis->command('del', [$this->key($channel, 'failures')]);
    }

    /**
     * @return bool true when this failure opened (or re-opened) the circuit
     */
    public function recordFailure(Channel $channel, bool $probe): bool
    {
        if ($probe) {
            $this->open($channel);
            $this->redis->command('del', [$this->key($channel, 'probe')]);

            return true;
        }

        $failures = $this->redis->command('incr', [$this->key($channel, 'failures')]);
        $this->redis->command('expire', [$this->key($channel, 'failures'), 60]);

        if (! is_int($failures) || $failures < $this->config->circuitFailureThreshold) {
            return false;
        }

        $this->open($channel);
        $this->redis->command('del', [$this->key($channel, 'failures')]);

        return true;
    }

    /**
     * The probe was allowed but never reached the provider (the notification
     * turned out to be cancelled, or no rate slot was free). Give the probe
     * back so the next job can try.
     */
    public function abandonProbe(Channel $channel): void
    {
        $this->redis->command('del', [$this->key($channel, 'probe')]);
    }

    /**
     * closed | open | half_open, for health and metrics.
     */
    public function state(Channel $channel): string
    {
        $remainingMs = $this->redis->command('pttl', [$this->key($channel, 'open')]);

        if (is_int($remainingMs) && $remainingMs > 0) {
            return 'open';
        }

        return $this->tripped($channel) ? 'half_open' : 'closed';
    }

    public function reset(Channel $channel): void
    {
        $this->redis->command('del', [
            $this->key($channel, 'open'),
            $this->key($channel, 'tripped'),
            $this->key($channel, 'probe'),
            $this->key($channel, 'failures'),
        ]);
    }

    private function tripped(Channel $channel): bool
    {
        $exists = $this->redis->command('exists', [$this->key($channel, 'tripped')]);

        return $exists === true || (is_int($exists) && $exists > 0);
    }

    private function open(Channel $channel): void
    {
        $this->redis->command('set', [$this->key($channel, 'open'), '1', ['ex' => $this->config->circuitCooldownSeconds]]);
        // Remembers that the circuit tripped after the cooldown key expires,
        // which is what makes the next call a probe instead of business as usual.
        $this->redis->command('set', [$this->key($channel, 'tripped'), '1', ['ex' => 3600]]);
    }

    private function key(Channel $channel, string $part): string
    {
        return "circuit:{$channel->value}:{$part}";
    }
}
