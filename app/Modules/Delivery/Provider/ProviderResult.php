<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Provider;

final readonly class ProviderResult
{
    public function __construct(
        public ProviderOutcome $outcome,
        public float $latencySeconds,
        public ?int $httpStatus = null,
        public ?string $messageId = null,
        public ?string $error = null,
        /** Seconds the provider asked us to wait (429 Retry-After). */
        public ?int $retryAfterSeconds = null,
    ) {}
}
