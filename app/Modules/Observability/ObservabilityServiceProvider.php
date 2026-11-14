<?php

declare(strict_types=1);

namespace App\Modules\Observability;

use App\Modules\Observability\Metrics\Metrics;
use App\Support\NotificationsConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Redis;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class, static function (Application $app): Metrics {
            $config = $app->make(Repository::class);
            $database = $app->make(NotificationsConfig::class)->metricsRedisDatabase;

            // A dedicated connection and database: metric keys stay apart
            // from queues and cache and can be wiped without touching them.
            $connect = static function () use ($config, $database): Redis {
                // The port arrives from the environment as a string.
                $port = $config->get('database.redis.default.port');

                $redis = new Redis;
                $redis->pconnect(
                    $config->string('database.redis.default.host'),
                    is_numeric($port) ? (int) $port : 6379,
                    1.0,
                    'metrics',
                );

                $password = $config->get('database.redis.default.password');
                if (is_string($password) && $password !== '') {
                    $redis->auth($password);
                }

                $redis->select($database);

                return $redis;
            };

            return new Metrics($connect, $app->make(LoggerInterface::class));
        });
    }
}
