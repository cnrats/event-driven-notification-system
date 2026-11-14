<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;
use Carbon\CarbonImmutable;

/**
 * A validated request to send one notification, not stored yet.
 */
final readonly class NotificationDraft
{
    public function __construct(
        public Channel $channel,
        public string $recipient,
        public string $content,
        public Priority $priority,
        public ?CarbonImmutable $scheduledAt,
        public ?string $idempotencyKey,
    ) {}

    /**
     * Fingerprint of what the client asked for. Stored next to the
     * idempotency key so a key reused with a different payload is detected
     * instead of silently returning the first request's result.
     */
    public function requestHash(): string
    {
        return hash('sha256', implode("\x1f", [
            $this->channel->value,
            $this->recipient,
            $this->content,
            $this->priority->value,
            $this->scheduledAt?->toIso8601ZuluString('millisecond') ?? '',
        ]));
    }
}
