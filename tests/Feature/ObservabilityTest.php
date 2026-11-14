<?php

declare(strict_types=1);

use App\Modules\Delivery\CircuitBreaker\ChannelCircuitBreaker;
use App\Modules\Notifications\Domain\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

it('reports degraded when the API works but nothing is delivering', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.workers.status', 'not_running')
        ->assertJsonPath('checks.sweeper.status', 'not_running')
        ->assertJsonPath('circuits.sms', 'closed');
});

it('reports a sweeper that stopped sweeping', function (): void {
    Cache::put('sweeper:heartbeat', time() - 120, 600);

    $this->getJson('/api/v1/health')->assertJsonPath('checks.sweeper.status', 'stalled');
});

it('shows an open circuit in the health report', function (): void {
    $this->configure(['circuit_breaker.failure_threshold' => 1]);
    resolve(ChannelCircuitBreaker::class)->recordFailure(Channel::Push, false);

    $this->getJson('/api/v1/health')->assertJsonPath('circuits.push', 'open')->assertJsonPath('circuits.sms', 'closed');
});

it('exposes queue depth, success and failure rates and latency as JSON', function (): void {
    // Real queue: four jobs wait on sms-normal, one on sms-high.
    providerResponds();
    deliver(accept(fakeQueue: false));
    deliver(accept(fakeQueue: false));
    providerResponds(503);
    deliver(accept(fakeQueue: false));
    providerResponds(400);
    deliver(accept(fakeQueue: false));
    accept(['priority' => 'high'], fakeQueue: false);

    $response = $this->getJson('/api/v1/metrics')->assertOk();

    $response->assertJsonPath('data.notifications.sent', 2)
        ->assertJsonPath('data.notifications.retrying', 1)
        ->assertJsonPath('data.notifications.failed', 1)
        ->assertJsonPath('data.notifications.queued', 1)
        ->assertJsonPath('data.queues.by_queue.sms-high.pending', 1)
        ->assertJsonPath('data.queues.by_queue.sms-normal.pending', 4)
        ->assertJsonPath('data.queues.depth', 5)
        ->assertJsonPath('data.delivery.last_minute.attempts', 4)
        ->assertJsonPath('data.delivery.last_minute.accepted', 2)
        ->assertJsonPath('data.delivery.last_minute.success_rate', 0.5)
        ->assertJsonPath('data.delivery.last_minute.failure_rate', 0.5)
        ->assertJsonPath('data.latency_ms.provider.count', 4)
        ->assertJsonPath('data.latency_ms.end_to_end.count', 2)
        ->assertJsonPath('data.rate_limits.sms.limit_per_second', 100)
        ->assertJsonPath('data.circuits.email', 'closed');
});

it('exposes the same counters in Prometheus format', function (): void {
    providerResponds();
    deliver(accept(['priority' => 'high']));
    providerResponds(503);
    deliver(accept());

    $body = $this->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();

    expect($body)
        ->toContain('notifications_created_total{channel="sms",priority="high"} 1')
        ->toContain('notifications_delivery_attempts_total{channel="sms",outcome="accepted"} 1')
        ->toContain('notifications_delivery_attempts_total{channel="sms",outcome="retryable"} 1')
        ->toContain('notifications_finished_total{channel="sms",status="sent"} 1')
        ->toContain('notifications_provider_request_duration_seconds_bucket')
        ->toContain('notifications_delivery_latency_seconds_count{channel="sms",priority="high"} 1')
        ->toContain('notifications_queue_depth{queue="sms-high"}')
        ->toContain('notifications_circuit_state{channel="sms"} 0')
        ->toContain('notifications_current{status="sent"} 1');
});

it('logs each delivery as a structured event with the correlation id', function (): void {
    Log::spy();
    providerResponds();

    $notification = accept();
    deliver($notification);

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'notification.sent'
        && $context['notification_id'] === $notification->id
        && $context['channel'] === 'sms'
        && $context['attempt'] === 1
        && $context['http_status'] === 202)->once();

    expect(Context::get('correlation_id'))->toBe('test-correlation-id');
});
