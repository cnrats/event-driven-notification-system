<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

/**
 * Lifecycle of a notification.
 *
 *   scheduled ─┐
 *              ├─► queued ─► processing ─► sent
 *   retrying ──┘               │  │
 *      ▲                       │  └─► failed ─► queued (manual retry)
 *      └───────────────────────┘
 *   scheduled | queued | retrying ─► cancelled
 *
 * The same rules are enforced a second time in SQL: every transition is a
 * conditional UPDATE (see NotificationLifecycle), so two processes can never
 * both win the same transition.
 */
enum NotificationStatus: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Queued, self::Cancelled, self::Failed],
            self::Queued => [self::Processing, self::Cancelled, self::Failed],
            self::Processing => [self::Sent, self::Retrying, self::Failed, self::Queued],
            self::Retrying => [self::Queued, self::Cancelled, self::Failed],
            self::Failed => [self::Queued],
            self::Sent, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * No worker will touch the notification again without a manual retry.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Cancelled], true);
    }

    /**
     * States from which the given transition is legal, as plain values for
     * a WHERE status IN (...) clause.
     *
     * @return list<string>
     */
    public static function sourcesOf(self $target): array
    {
        $sources = [];

        foreach (self::cases() as $status) {
            if ($status->canTransitionTo($target)) {
                $sources[] = $status->value;
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
