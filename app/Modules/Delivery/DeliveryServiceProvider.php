<?php

declare(strict_types=1);

namespace App\Modules\Delivery;

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Delivery\Console\SweepNotifications;
use App\Modules\Delivery\Provider\NotificationProvider;
use App\Modules\Delivery\Provider\WebhookProvider;
use App\Modules\Delivery\Queue\QueueDispatcher;
use App\Modules\Delivery\RateLimiting\ChannelRateLimiter;
use App\Modules\Notifications\Contracts\DispatchesNotifications;
use App\Support\NotificationsConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DispatchesNotifications::class, QueueDispatcher::class);
        $this->app->bind(NotificationProvider::class, WebhookProvider::class);

        $this->app->singleton(ChannelRateLimiter::class, static fn (Application $app): ChannelRateLimiter => new ChannelRateLimiter(
            $app->make(RedisManager::class)->connection(),
            $app->make(NotificationsConfig::class),
        ));

        $this->app->singleton(ChannelCircuitBreaker::class, static fn (Application $app): ChannelCircuitBreaker => new ChannelCircuitBreaker(
            $app->make(RedisManager::class)->connection(),
            $app->make(NotificationsConfig::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SweepNotifications::class]);
        }
    }
}
