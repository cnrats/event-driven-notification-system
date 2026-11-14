<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\NotificationsConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            NotificationsConfig::class,
            static fn (Application $app): NotificationsConfig => NotificationsConfig::fromRepository($app->make(Repository::class)),
        );
    }
}
