<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

/**
 * Request-scoped identifiers stored with each notification so that a worker
 * handling it minutes later can continue the same log and trace context.
 */
final readonly class RequestMeta
{
    public function __construct(
        public ?string $correlationId = null,
        public ?string $traceContext = null,
    ) {}
}
