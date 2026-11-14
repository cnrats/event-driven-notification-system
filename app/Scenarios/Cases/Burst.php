<?php

declare(strict_types=1);

namespace App\Scenarios\Cases;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Domain\Priority;
use App\Modules\Notifications\Models\Notification;
use App\Scenarios\Messages;
use App\Scenarios\Report;
use App\Scenarios\Scenario;
use App\Scenarios\ScenarioKit;

/**
 * A flash sale: 10,000 notifications across three channels arrive within a
 * few seconds, far more than the provider may receive per second.
 */
final class Burst implements Scenario
{
    private const int TOTAL = 10_000;

    public function name(): string
    {
        return 'burst';
    }

    public function title(): string
    {
        return '10,000 notifications arrive at once';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();
        $report = new Report($this->name(), $this->title());

        $startedAt = microtime(true);
        foreach (array_chunk(Messages::mixed(self::TOTAL), 1000) as $chunk) {
            $kit->submitBatch($chunk);
        }
        $acceptedIn = microtime(true) - $startedAt;

        $kit->waitUntilFinished(300);
        $drainedIn = microtime(true) - $startedAt;

        $stats = $kit->providerStats();
        $attempts = $kit->attemptsDistribution();

        $report
            ->equals('Every notification was delivered', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('None failed: waiting for the rate limit never cost a delivery attempt', 0, $kit->count(NotificationStatus::Failed))
            ->equals('Each one needed exactly one delivery attempt', self::TOTAL, $attempts[1] ?? 0)
            ->equals('The queue gave up on no job', 0, $kit->failedJobs())
            ->equals('The provider received each message exactly once', self::TOTAL, is_numeric($stats['requests'] ?? null) ? (int) $stats['requests'] : -1);

        $rates = [];
        foreach (Channel::cases() as $channel) {
            $seen = $kit->channelStats($stats, $channel);
            $rates[$channel->value] = $seen['max_in_any_second'];

            $report->atMost(
                "Most {$channel->value} messages the provider received in any one second",
                $kit->config->ratePerSecond($channel),
                $seen['max_in_any_second'],
            );
        }

        $median = $this->medianSecondsToDelivery();
        $report->check(
            'Higher priority was delivered sooner (median seconds: high < normal < low)',
            'high < normal < low',
            sprintf('%.1f < %.1f < %.1f', $median['high'], $median['normal'], $median['low']),
            $median['high'] < $median['normal'] && $median['normal'] < $median['low'],
        );

        return $report
            ->fact('notifications', self::TOTAL)
            ->fact('workers_per_channel', $kit->workersPerChannel())
            ->fact('api_seconds_to_accept_all', round($acceptedIn, 2))
            ->fact('api_notifications_per_second', (int) round(self::TOTAL / max($acceptedIn, 0.001)))
            ->fact('seconds_until_all_delivered', round($drainedIn, 1))
            ->fact('max_per_second_at_provider', $rates)
            ->fact('median_seconds_to_delivery', $median);
    }

    /**
     * @return array{high: float, normal: float, low: float}
     */
    private function medianSecondsToDelivery(): array
    {
        $median = [];

        foreach (Priority::cases() as $priority) {
            $seconds = Notification::query()
                ->where('priority', $priority->value)
                ->whereNotNull('sent_at')
                ->selectRaw('TIMESTAMPDIFF(MICROSECOND, created_at, sent_at) / 1000000 as seconds')
                ->pluck('seconds')
                ->map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0)
                ->sort()
                ->values();

            $median[$priority->value] = round((float) ($seconds->get(intdiv($seconds->count(), 2)) ?? 0.0), 1);
        }

        return ['high' => $median['high'], 'normal' => $median['normal'], 'low' => $median['low']];
    }
}
