<?php

declare(strict_types=1);

namespace App\Scenarios\Cases;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Scenarios\Messages;
use App\Scenarios\Report;
use App\Scenarios\Scenario;
use App\Scenarios\ScenarioKit;

/**
 * The provider accepts the message, but one in ten responses is lost. The
 * sender cannot tell "not delivered" from "delivered, answer lost", so it
 * must send again; the idempotency key keeps that from reaching the
 * recipient twice.
 */
final class LostResponse implements Scenario
{
    private const int TOTAL = 600;

    public function name(): string
    {
        return 'lost-response';
    }

    public function title(): string
    {
        return 'The provider accepts messages but 10 % of its responses are lost';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();
        $kit->provider(['lose_response_rate' => 0.1]);

        $kit->submitBatch(Messages::many(self::TOTAL, Channel::Sms));
        $kit->waitUntilFinished(240);

        $stats = $kit->providerStats();
        $int = static fn (string $key): int => is_numeric($stats[$key] ?? null) ? (int) $stats[$key] : -1;

        return (new Report($this->name(), $this->title()))
            ->equals('Every notification ended as sent', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('Recipients got each message exactly once (unique deliveries at the provider)', self::TOTAL, $int('delivered'))
            ->atLeast('Messages that were sent again and recognised by their idempotency key', 1, $int('duplicates_suppressed'))
            ->equals('The queue gave up on no job', 0, $kit->failedJobs())
            ->fact('notifications', self::TOTAL)
            ->fact('responses_lost', $int('responses_lost'))
            ->fact('resends_not_delivered_twice', $int('duplicates_suppressed'))
            ->fact('notifications_by_attempts_needed', $kit->attemptsDistribution());
    }
}
