<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Provider;

use App\Modules\Notifications\Domain\Channel;

final readonly class OutboundMessage
{
    public function __construct(
        /** Sent as the Idempotency-Key header so a resend is not delivered twice. */
        public string $notificationId,
        public Channel $channel,
        public string $recipient,
        public string $content,
        public ?string $correlationId = null,
        public ?string $traceContext = null,
    ) {}
}
