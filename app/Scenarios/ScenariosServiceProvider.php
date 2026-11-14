<?php

declare(strict_types=1);

namespace App\Scenarios;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Scenarios\Cases\Burst;
use App\Scenarios\Cases\Cancel;
use App\Scenarios\Cases\Crash;
use App\Scenarios\Cases\Flaky;
use App\Scenarios\Cases\Idempotency;
use App\Scenarios\Cases\LostResponse;
use App\Scenarios\Cases\Outage;
use App\Scenarios\Cases\Schedule;
use App\Scenarios\Cases\Throttle;
use App\Support\NotificationsConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

/**
 * Demo tooling, not part of the product: the scenarios behind `make demo`.
 */
final class ScenariosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScenarioKit::class, static fn (Application $app): ScenarioKit => new ScenarioKit(
            $app->make(Factory::class),
            $app->make(RedisManager::class)->connection(),
            $app->make(ChannelRateLimiter::class),
            $app->make(ChannelCircuitBreaker::class),
            $app->make(NotificationsConfig::class),
            // Where the scenarios reach the public API (inside the compose network).
            $app->make(Repository::class)->string('notifications.scenarios.api_url'),
        ));

        $this->app->singleton(RunScenario::class, static fn (Application $app): RunScenario => new RunScenario(
            [
                new Burst,
                new Flaky,
                new LostResponse,
                new Throttle,
                new Outage,
                new Crash,
                new Cancel,
                new Idempotency,
                new Schedule,
            ],
            $app->make(ScenarioKit::class),
            $app->basePath('docs/results'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RunScenario::class]);
        }
    }
}
