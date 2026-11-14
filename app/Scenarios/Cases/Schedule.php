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
use App\Support\DbTime;
use Illuminate\Support\Sleep;

/**
 * 200 notifications are scheduled for a moment a few seconds ahead.
 */
final class Schedule implements Scenario
{
    private const int TOTAL = 200;

    private const int SECONDS_AHEAD = 6;

    public function name(): string
    {
        return 'schedule';
    }

    public function title(): string
    {
        return '200 notifications are scheduled for a moment in the future';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();

        $at = DbTime::now()->addSeconds(self::SECONDS_AHEAD);
        $kit->submitBatch(Messages::many(self::TOTAL, Channel::Email, extra: ['scheduled_at' => $at->toIso8601ZuluString('millisecond')]));

        // Half way to the scheduled time nothing may have left yet.
        Sleep::usleep(self::SECONDS_AHEAD * 500_000);
        $early = $kit->channelStats($kit->providerStats(), Channel::Email)['requests'];
        $waiting = $kit->count(NotificationStatus::Scheduled);

        $kit->waitUntilFinished(120);

        $sentEarly = Notification::query()->whereColumn('sent_at', '<', 'scheduled_at')->count();
        $delays = Notification::query()
            ->whereNotNull('sent_at')
            ->selectRaw('TIMESTAMPDIFF(MICROSECOND, scheduled_at, sent_at) / 1000000 as seconds')
            ->pluck('seconds')
            ->map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0)
            ->sort()
            ->values();

        $p95 = round((float) ($delays->get((int) floor($delays->count() * 0.95)) ?? 0.0), 2);

        return (new Report($this->name(), $this->title()))
            ->equals('Before the scheduled time all were waiting', self::TOTAL, $waiting)
            ->equals('Before the scheduled time the provider had received nothing', 0, $early)
            ->equals('All were delivered', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('None was sent before its scheduled time', 0, $sentEarly)
            // 200 messages at the rate limit take about two seconds on their own.
            ->atMost('Seconds after the scheduled time until delivery (95th percentile)', 5, $p95)
            ->fact('notifications', self::TOTAL)
            ->fact('scheduled_seconds_ahead', self::SECONDS_AHEAD)
            ->fact('p95_seconds_after_scheduled_time', $p95);
    }
}
