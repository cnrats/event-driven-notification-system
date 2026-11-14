<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Queue;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;

/**
 * One Redis queue per channel and priority ("sms-high", "email-low", ...).
 *
 * Per channel, so a channel that is throttled or down never holds back the
 * others. Per priority, because a Laravel worker drains its queues strictly
 * in the order they are listed.
 */
final class QueueRouter
{
    public static function queueFor(Channel $channel, Priority $priority): string
    {
        return $channel->value.'-'.$priority->value;
    }

    /**
     * Order for the main worker pool: high before normal before low.
     *
     * @return list<string>
     */
    public static function priorityOrder(Channel $channel): array
    {
        return array_map(
            static fn (Priority $priority): string => self::queueFor($channel, $priority),
            Priority::cases(),
        );
    }

    /**
     * Order for the small fairness pool: low first, so low priority keeps
     * moving while a long high-priority backlog is being drained.
     *
     * @return list<string>
     */
    public static function fairnessOrder(Channel $channel): array
    {
        return array_reverse(self::priorityOrder($channel));
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(self::priorityOrder(...), Channel::cases()));
    }
}
