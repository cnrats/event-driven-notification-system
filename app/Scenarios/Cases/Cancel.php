<?php

declare(strict_types=1);

namespace App\Scenarios\Cases;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Domain\Priority;
use App\Scenarios\Messages;
use App\Scenarios\Report;
use App\Scenarios\Scenario;
use App\Scenarios\ScenarioKit;
use Illuminate\Support\Sleep;

/**
 * A campaign is cancelled while it is being delivered. Jobs for the
 * cancelled notifications are already in the queue.
 */
final class Cancel implements Scenario
{
    private const int TOTAL = 1000;

    public function name(): string
    {
        return 'cancel';
    }

    public function title(): string
    {
        return 'A batch is cancelled while it is being delivered';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();

        $batchId = $kit->submitBatch(Messages::many(self::TOTAL, Channel::Sms, Priority::Low));

        // Let some go out first, then cancel the rest.
        $kit->waitUntil(fn (): bool => $kit->count(NotificationStatus::Sent) >= 150, 30);
        $kit->post("/api/v1/batches/{$batchId}/cancel")->throw();

        $kit->waitUntilFinished(120);
        // Give jobs of cancelled notifications time to be picked up and dropped.
        Sleep::usleep(3_000_000);

        $sent = $kit->count(NotificationStatus::Sent);
        $cancelled = $kit->count(NotificationStatus::Cancelled);
        $requests = $kit->channelStats($kit->providerStats(), Channel::Sms)['requests'];

        return (new Report($this->name(), $this->title()))
            ->atLeast('Notifications cancelled before they were sent', 1, $cancelled)
            ->equals('Sent plus cancelled is everything', self::TOTAL, $sent + $cancelled)
            ->equals('The provider received exactly the ones that were not cancelled', $sent, $requests)
            ->fact('notifications', self::TOTAL)
            ->fact('sent_before_the_cancel_took_effect', $sent)
            ->fact('cancelled', $cancelled);
    }
}
