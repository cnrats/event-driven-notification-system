<?php

declare(strict_types=1);

namespace App\Modules\Delivery\RateLimiting;

final readonly class Reservation
{
    public function __construct(
        /** False: no slot was taken because the wait would be too long. */
        public bool $granted,
        /** Microseconds until the reserved slot (or until one would be free). */
        public int $waitMicros,
    ) {}

    public function waitSeconds(): float
    {
        return $this->waitMicros / 1_000_000;
    }
}
