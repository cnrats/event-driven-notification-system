<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;

/**
 * The minimum the delivery side needs to route a notification to a queue.
 */
final readonly class QueueTicket
{
    public function __construct(
        public string $notificationId,
        public Channel $channel,
        public Priority $priority,
    ) {}
}
