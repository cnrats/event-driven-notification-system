<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Domain\ChannelRules;
use App\Support\NotificationsConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRules::class, static function (Application $app): ChannelRules {
            $config = $app->make(NotificationsConfig::class);

            return new ChannelRules(
                smsMaxSegments: $config->smsMaxSegments,
                emailMaxLength: $config->emailMaxLength,
                pushMaxLength: $config->pushMaxLength,
            );
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
