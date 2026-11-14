<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Models\Notification;

final readonly class CreatedNotification
{
    public function __construct(
        public Notification $notification,
        /** True when an idempotency key matched an earlier request. */
        public bool $replayed,
    ) {}
}
