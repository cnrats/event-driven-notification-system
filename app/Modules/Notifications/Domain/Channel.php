<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

/**
 * A delivery channel. Adding one (WhatsApp, RCS, ...) means adding a case
 * here, its limits in config/notifications.php and its rules in ChannelRules;
 * queues, workers, rate limiter and metrics are all derived from this enum.
 */
enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
