<?php

declare(strict_types=1);

namespace App\Modules\Delivery\CircuitBreaker;

final readonly class CircuitDecision
{
    private function __construct(
        public bool $allowed,
        /** This call is the single trial request of a half-open circuit. */
        public bool $probe,
        public int $retryAfterSeconds,
    ) {}

    public static function allow(): self
    {
        return new self(true, false, 0);
    }

    public static function probe(): self
    {
        return new self(true, true, 0);
    }

    public static function reject(int $retryAfterSeconds): self
    {
        return new self(false, false, max(1, $retryAfterSeconds));
    }
}
