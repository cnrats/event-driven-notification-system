<?php

declare(strict_types=1);

namespace App\Scenarios;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs end-to-end scenarios against the running stack and writes what was
 * measured to docs/results. The README's result table is taken from there.
 *
 * Scenarios wipe notifications and queues: demo environments only.
 */
#[Description('Run an end-to-end scenario against the running stack and record the measurements')]
#[Signature('scenario:run {name=all : Scenario name, or "all"} {--list : List the scenarios} {--as= : Store the result under this name instead}')]
final class RunScenario extends Command
{
    /**
     * @param  list<Scenario>  $scenarios
     */
    public function __construct(
        private readonly array $scenarios,
        private readonly string $resultsPath,
    ) {
        parent::__construct();
    }

    /**
     * The kit is injected here, not into the constructor: Artisan builds
     * every command on start, and the kit opens a Redis connection. Listing
     * commands or building the image must not need Redis.
     */
    public function handle(ScenarioKit $kit): int
    {
        if ($this->option('list') === true) {
            $this->table(['Name', 'What happens'], array_map(
                static fn (Scenario $scenario): array => [$scenario->name(), $scenario->title()],
                $this->scenarios,
            ));

            return self::SUCCESS;
        }

        $name = $this->argument('name');
        $selected = array_values(array_filter(
            $this->scenarios,
            static fn (Scenario $scenario): bool => $name === 'all' || $scenario->name() === $name,
        ));

        if ($selected === []) {
            $this->error(sprintf('Unknown scenario "%s". Use --list to see the available ones.', $name));

            return self::INVALID;
        }

        $allPassed = true;

        foreach ($selected as $scenario) {
            $this->newLine();
            $this->line("<options=bold>{$scenario->name()}</>: {$scenario->title()}");

            $startedAt = microtime(true);

            try {
                $report = $scenario->run($kit);
            } catch (Throwable $e) {
                $report = (new Report($scenario->name(), $scenario->title()))
                    ->check('The scenario ran to completion', 'no error', $e->getMessage(), false);
            }

            $report->fact('scenario_seconds', round(microtime(true) - $startedAt, 1));
            $report->fact('measured_at', gmdate('Y-m-d\TH:i:s\Z'));

            $this->render($report);
            $this->store($report);
            $allPassed = $allPassed && $report->passed();
        }

        // Leave the stack clean and the provider well-behaved.
        $kit->reset();

        $this->newLine();
        $allPassed ? $this->info('All checks passed.') : $this->error('Some checks failed.');

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function render(Report $report): void
    {
        $this->table(['', 'Claim', 'Expected', 'Measured'], array_map(
            static fn (array $check): array => [
                $check['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>',
                $check['claim'],
                $check['expected'],
                $check['measured'],
            ],
            $report->checks(),
        ));

        foreach ($report->facts() as $name => $value) {
            $this->line(sprintf('  <comment>%s</comment>: %s', $name, is_scalar($value) ? (string) $value : json_encode($value)));
        }
    }

    private function store(Report $report): void
    {
        if (! is_dir($this->resultsPath) && ! @mkdir($this->resultsPath, 0775, true)) {
            return;
        }

        $as = $this->option('as');
        $name = is_string($as) && preg_match('/^[a-z0-9-]+$/', $as) === 1 ? $as : $report->name;

        @file_put_contents(
            "{$this->resultsPath}/{$name}.json",
            json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }
}
