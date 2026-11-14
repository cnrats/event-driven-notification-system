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
 * The provider goes down completely for twelve seconds while 900
 * notifications are being delivered.
 */
final class Outage implements Scenario
{
    private const int TOTAL = 900;

    private const int DOWN_FOR_SECONDS = 12;

    public function name(): string
    {
        return 'outage';
    }

    public function title(): string
    {
        return 'The provider is down for twelve seconds';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();
        $kit->submitBatch(Messages::many(self::TOTAL, Channel::Sms));

        $kit->waitUntil(fn (): bool => $kit->channelStats($kit->providerStats(), Channel::Sms)['requests'] >= 100, 30);
        $kit->provider(['down_seconds' => self::DOWN_FOR_SECONDS]);

        $kit->waitUntilFinished(240);

        $seen = $kit->channelStats($kit->providerStats(), Channel::Sms);
        $attempts = $kit->attemptsDistribution();
        $rate = $kit->config->ratePerSecond(Channel::Sms);

        return (new Report($this->name(), $this->title()))
            ->equals('Every notification was delivered once the provider was back', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('None failed', 0, $kit->count(NotificationStatus::Failed))
            // The circuit opens after 20 consecutive failures; a few more are
            // already in flight at that moment.
            ->atMost('Requests sent into the outage before the circuit opened', 60, $seen['failed'])
            ->atMost('Most delivery attempts any notification needed', 3, $kit->maxAttemptsUsed())
            ->fact('notifications', self::TOTAL)
            ->fact('seconds_down', self::DOWN_FOR_SECONDS)
            ->fact('requests_sent_into_the_outage', $seen['failed'])
            ->fact('requests_a_sender_without_circuit_breaker_would_have_sent', $rate * self::DOWN_FOR_SECONDS)
            ->fact('notifications_by_attempts_needed', $attempts);
    }
}
