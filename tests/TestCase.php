<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Observability\Metrics\Metrics;
use App\Support\NotificationsConfig;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    // Truncation, not a wrapping transaction: the code under test relies on
    // its own transactions and row locks behaving as they do in production.
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Dedicated Redis databases (see phpunit.xml), wiped before each test.
        Redis::connection()->command('flushdb');
        Redis::connection('cache')->command('flushdb');
        $this->app->make(Metrics::class)->wipe();

        // No test may reach a real provider by accident.
        Http::preventStrayRequests();

        // Workers wait for their rate-limit slot; tests do not need to.
        Sleep::fake();
    }

    /**
     * Change notification settings for one test and rebuild what caches them.
     *
     * @param  array<string, mixed>  $settings  keys relative to config('notifications')
     */
    protected function configure(array $settings): void
    {
        foreach ($settings as $key => $value) {
            config(["notifications.{$key}" => $value]);
        }

        foreach ([NotificationsConfig::class, ChannelRateLimiter::class, ChannelCircuitBreaker::class] as $cached) {
            $this->app->forgetInstance($cached);
        }
    }
}
