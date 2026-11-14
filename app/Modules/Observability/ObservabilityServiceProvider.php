<?php

declare(strict_types=1);

namespace App\Modules\Observability;

use App\Modules\Observability\Http\Controllers\HealthController;
use App\Modules\Observability\Http\Controllers\MetricsController;
use App\Modules\Observability\Metrics\Metrics;
use App\Modules\Observability\Tracing\Tracing;
use App\Support\NotificationsConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
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

        $this->app->singleton(Tracing::class, static function (Application $app): Tracing {
            $config = $app->make(Repository::class);

            if (! $config->boolean('notifications.tracing.enabled')) {
                return new Tracing(NoopTracer::getInstance());
            }

            $exporter = new SpanExporter((new OtlpHttpTransportFactory)->create(
                rtrim($config->string('notifications.tracing.endpoint'), '/').'/v1/traces',
                'application/json',
            ));

            $provider = TracerProvider::builder()
                // Spans are queued in memory and exported in batches when
                // Tracing::flush() is called, never while a request or a
                // delivery is waiting.
                ->addSpanProcessor(new BatchSpanProcessor($exporter, Clock::getDefault(), 8192, 2000, 3000, 512, false))
                ->setResource(ResourceInfo::create(Attributes::create([
                    'service.name' => $config->string('notifications.tracing.service_name'),
                ])))
                ->setSampler(new ParentBased(new AlwaysOnSampler))
                ->build();

            return new Tracing($provider->getTracer('notification-system'), $provider);
        });
    }

    public function boot(): void
    {
        // Long-running workers export spans every couple of seconds and once
        // more when they stop.
        Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->app->make(Tracing::class)->flushIfDue());
        Event::listen(WorkerStopping::class, fn () => $this->app->make(Tracing::class)->flush());

        Route::middleware('api')->prefix('api/v1')->group(static function (): void {
            Route::get('health', HealthController::class);
            Route::get('metrics', [MetricsController::class, 'json']);
        });

        // Scrape endpoint for Prometheus, in the text exposition format.
        Route::get('metrics', [MetricsController::class, 'prometheus']);
    }
}
