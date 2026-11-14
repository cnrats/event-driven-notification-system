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

/**
 * Worker processes are killed with SIGKILL while their request to the
 * provider is in flight: the provider has the message, the worker never
 * records that. This is the window where a system either loses messages or
 * delivers them twice.
 *
 * Must run in the container that hosts the workers (`make demo` does).
 */
final class Crash implements Scenario
{
    private const int TOTAL = 10;

    public function name(): string
    {
        return 'crash';
    }

    public function title(): string
    {
        return 'Workers are killed while their request is in flight';
    }

    public function run(ScenarioKit $kit): Report
    {
        $report = new Report($this->name(), $this->title());

        if ($this->workerPids() === []) {
            return $report->check('Worker processes are visible to the scenario', 'at least 1', 0, false)
                ->fact('hint', 'Run it in the worker container: docker compose exec horizon php artisan scenario:run crash');
        }

        $kit->reset();
        // Slow provider, so every worker is caught waiting for a response.
        $kit->provider(['latency_ms' => 4000]);
        $kit->submitBatch(Messages::many(self::TOTAL, Channel::Sms, Priority::High));

        $inFlight = $kit->workersPerChannel();
        $kit->waitUntil(fn (): bool => $kit->channelStats($kit->providerStats(), Channel::Sms)['requests'] >= $inFlight, 30);

        $killedAt = microtime(true);
        $killed = 0;
        foreach ($this->workerPids() as $pid) {
            $killed += posix_kill($pid, SIGKILL) ? 1 : 0;
        }

        $kit->provider(['latency_ms' => 5]);
        $kit->waitUntilFinished(180);
        $recoveredIn = microtime(true) - $killedAt;

        $stats = $kit->providerStats();
        $int = static fn (string $key): int => is_numeric($stats[$key] ?? null) ? (int) $stats[$key] : -1;

        return $report
            ->atLeast('Worker processes killed mid-request', 1, $killed)
            ->equals('No notification was lost: all ended as sent', self::TOTAL, $kit->count(NotificationStatus::Sent))
            ->equals('Recipients got each message exactly once (unique deliveries at the provider)', self::TOTAL, $int('delivered'))
            ->atLeast('Messages resent after the crash and recognised by their idempotency key', 1, $int('duplicates_suppressed'))
            ->fact('notifications', self::TOTAL)
            ->fact('workers_killed', $killed)
            ->fact('seconds_until_everything_was_delivered', round($recoveredIn, 1))
            ->fact('lease_seconds', $kit->config->leaseSeconds)
            ->fact('notifications_by_attempts_needed', $kit->attemptsDistribution());
    }

    /**
     * Process ids of the SMS queue workers in this container.
     *
     * @return list<int>
     */
    private function workerPids(): array
    {
        $pids = [];

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $command = str_replace("\0", ' ', (string) @file_get_contents($file));

            if (str_contains($command, 'horizon:work') && str_contains($command, '--queue=sms-')) {
                $pids[] = (int) basename(dirname($file));
            }
        }

        return $pids;
    }
}
