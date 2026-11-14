<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use Carbon\CarbonImmutable;

/**
 * Raised after a status transition has been written. Other modules react to
 * it (real-time broadcast); the transition itself never depends on them.
 */
final readonly class NotificationStatusChanged
{
    public function __construct(
        public string $notificationId,
        public ?string $batchId,
        public Channel $channel,
        public NotificationStatus $status,
        public int $attempts,
        public CarbonImmutable $occurredAt,
        public ?string $detail = null,
    ) {}
}
