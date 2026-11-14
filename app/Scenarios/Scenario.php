<?php

declare(strict_types=1);

namespace App\Scenarios;

/**
 * An end-to-end experiment: drive the running system through the public API,
 * misbehave on the provider side, then measure what actually happened.
 */
interface Scenario
{
    public function name(): string;

    public function title(): string;

    public function run(ScenarioKit $kit): Report;
}
