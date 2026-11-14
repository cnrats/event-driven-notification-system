<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Notifications\Domain\Channel;
use Illuminate\Contracts\Config\Repository;

/**
 * Typed view of config/notifications.php, built once per process. Code reads
 * settings from here instead of calling config() with string keys.
 */
final readonly class NotificationsConfig
{
    /**
     * @param  array<string, int>  $ratePerSecond  keyed by channel value
     */
    public function __construct(
        public string $providerUrl,
        public float $providerConnectTimeoutSeconds,
        public float $providerTimeoutSeconds,
        public array $ratePerSecond,
        public int $smsMaxSegments,
        public int $emailMaxLength,
        public int $pushMaxLength,
        public float $rateSafetyMargin,
        public int $rateMaxBlockMs,
        public int $rateMaxLatenessMs,
        public int $maxAttempts,
        public int $leaseSeconds,
        public int $ttlSeconds,
        public int $backoffBaseSeconds,
        public int $backoffFactor,
        public int $backoffMaxSeconds,
        public int $circuitFailureThreshold,
        public int $circuitCooldownSeconds,
        public int $circuitProbeLockSeconds,
        public int $sweeperIntervalMs,
        public int $sweeperChunk,
        public int $sweeperUnqueuedGraceSeconds,
        public int $sweeperLeaseGraceSeconds,
        public int $sweeperStaleAfterSeconds,
        public int $batchMaxSize,
        public int $workersPerChannel,
        public int $fairWorkersPerChannel,
        public int $metricsRedisDatabase,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $rates = [];

        foreach (Channel::cases() as $channel) {
            $rates[$channel->value] = $config->integer("notifications.channels.{$channel->value}.rate_per_second");
        }

        return new self(
            providerUrl: $config->string('notifications.provider.url'),
            providerConnectTimeoutSeconds: $config->float('notifications.provider.connect_timeout_seconds'),
            providerTimeoutSeconds: $config->float('notifications.provider.timeout_seconds'),
            ratePerSecond: $rates,
            smsMaxSegments: $config->integer('notifications.channels.sms.max_segments'),
            emailMaxLength: $config->integer('notifications.channels.email.max_length'),
            pushMaxLength: $config->integer('notifications.channels.push.max_length'),
            rateSafetyMargin: $config->float('notifications.rate_limit.safety_margin'),
            rateMaxBlockMs: $config->integer('notifications.rate_limit.max_block_ms'),
            rateMaxLatenessMs: $config->integer('notifications.rate_limit.max_lateness_ms'),
            maxAttempts: $config->integer('notifications.delivery.max_attempts'),
            leaseSeconds: $config->integer('notifications.delivery.lease_seconds'),
            ttlSeconds: $config->integer('notifications.delivery.ttl_seconds'),
            backoffBaseSeconds: $config->integer('notifications.delivery.backoff.base_seconds'),
            backoffFactor: $config->integer('notifications.delivery.backoff.factor'),
            backoffMaxSeconds: $config->integer('notifications.delivery.backoff.max_seconds'),
            circuitFailureThreshold: $config->integer('notifications.circuit_breaker.failure_threshold'),
            circuitCooldownSeconds: $config->integer('notifications.circuit_breaker.cooldown_seconds'),
            circuitProbeLockSeconds: $config->integer('notifications.circuit_breaker.probe_lock_seconds'),
            sweeperIntervalMs: $config->integer('notifications.sweeper.interval_ms'),
            sweeperChunk: $config->integer('notifications.sweeper.chunk'),
            sweeperUnqueuedGraceSeconds: $config->integer('notifications.sweeper.unqueued_grace_seconds'),
            sweeperLeaseGraceSeconds: $config->integer('notifications.sweeper.lease_grace_seconds'),
            sweeperStaleAfterSeconds: $config->integer('notifications.sweeper.stale_after_seconds'),
            batchMaxSize: $config->integer('notifications.batch.max_size'),
            workersPerChannel: $config->integer('notifications.workers.per_channel'),
            fairWorkersPerChannel: $config->integer('notifications.workers.fair_per_channel'),
            metricsRedisDatabase: $config->integer('notifications.metrics.redis_database'),
        );
    }

    public function ratePerSecond(Channel $channel): int
    {
        return $this->ratePerSecond[$channel->value];
    }
}
