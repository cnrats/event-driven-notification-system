<?php

declare(strict_types=1);

namespace App\Modules\Delivery;

/**
 * What the job should do after the handler ran.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        /** Null: the job is finished. Otherwise put it back and retry after this many seconds. */
        public ?int $releaseAfterSeconds,
        public string $reason,
    ) {}

    public static function done(string $reason): self
    {
        return new self(null, $reason);
    }

    public static function release(int $seconds, string $reason): self
    {
        return new self(max(1, $seconds), $reason);
    }
}
