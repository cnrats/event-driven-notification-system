<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;

/**
 * Builds request payloads for the scenarios.
 */
final class Messages
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function one(Channel $channel, int $number, Priority $priority = Priority::Normal, array $extra = []): array
    {
        return [
            'channel' => $channel->value,
            'recipient' => match ($channel) {
                Channel::Sms => sprintf('+90555%07d', $number),
                Channel::Email => "user{$number}@example.com",
                Channel::Push => sprintf('device-token-%08d', $number),
            },
            'content' => "Scenario message {$number}",
            'priority' => $priority->value,
        ] + $extra;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    public static function many(int $count, Channel $channel, Priority $priority = Priority::Normal, array $extra = []): array
    {
        $items = [];

        for ($number = 0; $number < $count; $number++) {
            $items[] = self::one($channel, $number, $priority, $extra);
        }

        return $items;
    }

    /**
     * A realistic campaign mix across all channels: 10 % high, 30 % normal,
     * 60 % low priority.
     *
     * @return list<array<string, mixed>>
     */
    public static function mixed(int $count): array
    {
        $channels = Channel::cases();
        $items = [];

        for ($number = 0; $number < $count; $number++) {
            $bucket = intdiv($number, count($channels)) % 10;
            $priority = match (true) {
                $bucket === 0 => Priority::High,
                $bucket <= 3 => Priority::Normal,
                default => Priority::Low,
            };

            $items[] = self::one($channels[$number % count($channels)], $number, $priority);
        }

        return $items;
    }
}
