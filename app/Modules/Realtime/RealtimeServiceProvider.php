<?php

declare(strict_types=1);

namespace App\Modules\Realtime;

use App\Modules\Notifications\Events\NotificationStatusChanged;
use App\Modules\Realtime\Listeners\BroadcastStatusChange;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Real-time status updates over WebSocket (Laravel Reverb).
 *
 * The module only listens: Notifications raises NotificationStatusChanged
 * and knows nothing about broadcasting.
 */
final class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(Repository $config): void
    {
        if ($config->boolean('notifications.realtime.enabled')) {
            Event::listen(NotificationStatusChanged::class, BroadcastStatusChange::class);
        }

        // A dependency-free page that subscribes and shows the stream.
        Route::view('/live', 'live', [
            'key' => $config->string('broadcasting.connections.reverb.key'),
            'port' => $config->integer('notifications.realtime.public_port'),
        ]);
    }
}
