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
 * Mid-delivery the provider starts answering 429 with Retry-After: 2 and
 * keeps doing so for six seconds.
 */
final class Throttle implements Scenario
{
    private const int TOTAL = 800;

    private const int THROTTLED_FOR_SECONDS = 6;

    public function name(): string
    {
        return 'throttle';
    }

    public function title(): string
    {
        return 'The provider answers 429 with Retry-After for six seconds';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();
        $kit->submitBatch(Messages::many(self::TOTAL, Channel::Sms));

        $kit->waitUntil(fn (): bool => $kit->channelStats($kit->providerStats(), Channel::Sms)['requests'] >= 100, 30);
        $kit->provider(['throttle_seconds' => self::THROTTLED_FOR_SECONDS, 'retry_after' => 2]);

        $kit->waitUntilFinished(180);

        $seen = $kit->channelStats($kit->providerStats(), Channel::Sms);
        $attempts = $kit->attemptsDistribution();
        $rate = $kit->config->ratePerSecond(Channel::Sms);

        return (new Report($this->name(), $this->title()))
            ->equals('Every notification was delivered', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('Being throttled cost no delivery attempt (all delivered on attempt 1)', self::TOTAL, $attempts[1] ?? 0)
            // Without a shared pause every job would hit the provider to find
            // out: about rate x seconds requests answered with 429.
            ->atMost('Requests answered with 429 (the whole channel paused after the first ones)', 60, $seen['throttled'])
            ->atMost('Most requests in any one second', $rate, $seen['max_in_any_second'])
            ->fact('notifications', self::TOTAL)
            ->fact('seconds_throttled', self::THROTTLED_FOR_SECONDS)
            ->fact('requests_answered_429', $seen['throttled'])
            ->fact('requests_a_naive_sender_would_have_wasted', $rate * self::THROTTLED_FOR_SECONDS);
    }
}
