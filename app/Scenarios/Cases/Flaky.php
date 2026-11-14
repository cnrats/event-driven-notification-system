<?php

declare(strict_types=1);

namespace App\Scenarios\Cases;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\Notification;
use App\Scenarios\Messages;
use App\Scenarios\Report;
use App\Scenarios\Scenario;
use App\Scenarios\ScenarioKit;

/**
 * The provider fails 30 % of all requests with HTTP 503.
 */
final class Flaky implements Scenario
{
    private const int PER_CHANNEL = 300;

    public function name(): string
    {
        return 'flaky';
    }

    public function title(): string
    {
        return 'The provider fails 30 % of requests';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();
        $kit->provider(['fail_rate' => 0.3]);
        $total = self::PER_CHANNEL * count(Channel::cases());

        foreach (Channel::cases() as $channel) {
            $kit->submitBatch(Messages::many(self::PER_CHANNEL, $channel));
        }

        $kit->waitUntilFinished(240);

        $stats = $kit->providerStats();
        $sent = $kit->count(NotificationStatus::Sent);
        $failed = $kit->count(NotificationStatus::Failed);
        $attempts = $kit->attemptsDistribution();
        $gaveUp = Notification::query()->where('status', 'failed')->where('last_error', 'like', 'gave up after %')->count();

        $report = (new Report($this->name(), $this->title()))
            ->equals('Every notification reached a final state', $total, $sent + $failed)
            // Five attempts at a 30 % failure rate all fail with probability 0.24 %.
            ->atLeast('Share delivered thanks to retries', 0.98, round($sent / $total, 4))
            ->equals('The provider delivered each sent notification exactly once', $sent, is_numeric($stats['delivered'] ?? null) ? (int) $stats['delivered'] : -1)
            ->atMost('Most delivery attempts used by any notification', $kit->config->maxAttempts, $kit->maxAttemptsUsed())
            ->equals('Every failure is explained on the notification ("gave up after 5 attempts")', $failed, $gaveUp)
            ->equals('The queue gave up on no job', 0, $kit->failedJobs());

        foreach (Channel::cases() as $channel) {
            $report->atMost(
                "Most {$channel->value} requests in any one second, retries included",
                $kit->config->ratePerSecond($channel),
                $kit->channelStats($stats, $channel)['max_in_any_second'],
            );
        }

        return $report
            ->fact('notifications', $total)
            ->fact('provider_failure_rate', 0.3)
            ->fact('delivered', $sent)
            ->fact('failed_after_all_attempts', $failed)
            ->fact('notifications_by_attempts_needed', $attempts)
            ->fact('requests_the_provider_failed', $stats['failed'] ?? null);
    }
}
