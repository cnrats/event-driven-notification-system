<?php

declare(strict_types=1);

use App\Modules\Delivery\DeliveryOutcome;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Observability\Tracing\Tracing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/*
 * Tracing is off in the rest of the suite. Here spans are collected in
 * memory instead of being sent to a collector.
 */

beforeEach(function (): void {
    $this->exporter = new InMemoryExporter;
    $provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));

    $this->app->instance(Tracing::class, new Tracing($provider->getTracer('test'), $provider));
    Queue::fake();
});

/**
 * @return array<string, ImmutableSpan> finished spans by name
 */
function spans(InMemoryExporter $exporter): array
{
    $spans = [];

    foreach ($exporter->getSpans() as $span) {
        $spans[$span->getName()] = $span;
    }

    return $spans;
}

it('follows a notification from the API request to the provider call in one trace', function (): void {
    $id = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello',
    ])->assertCreated()->json('data.id');

    $notification = Notification::query()->findOrFail($id);
    providerResponds();
    deliver($notification);

    $spans = spans($this->exporter);
    $request = $spans['POST /api/v1/notifications'];
    $delivery = $spans['notification.deliver'];
    $wait = $spans['rate_limit.wait'];
    $send = $spans['provider.send'];

    // One trace across two processes: the worker continues what the API began.
    expect($delivery->getTraceId())->toBe($request->getTraceId())
        ->and($delivery->getParentSpanId())->toBe($request->getSpanId())
        ->and($wait->getParentSpanId())->toBe($delivery->getSpanId())
        ->and($send->getParentSpanId())->toBe($delivery->getSpanId())
        ->and($request->getKind())->toBe(SpanKind::KIND_SERVER)
        ->and($delivery->getKind())->toBe(SpanKind::KIND_CONSUMER)
        ->and($send->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($delivery->getAttributes()->get('notification.id'))->toBe($id)
        ->and($delivery->getAttributes()->get('delivery.outcome'))->toBe('accepted')
        ->and($send->getAttributes()->get('http.response.status_code'))->toBe(202);

    // The provider receives the trace context and can continue it.
    Http::assertSent(fn (Request $outgoing): bool => str_starts_with(
        $outgoing->header('traceparent')[0] ?? '',
        sprintf('00-%s-%s-', $send->getTraceId(), $send->getSpanId()),
    ));
});

it('continues the trace of a caller that sends a traceparent header', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello',
    ], ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])->assertCreated();

    $request = spans($this->exporter)['POST /api/v1/notifications'];

    expect($request->getTraceId())->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($request->getParentSpanId())->toBe('b7ad6b7169203331');
});

it('traces only the first notifications of a batch into delivery', function (): void {
    $this->configure(['tracing.traced_per_batch' => 2]);

    $this->postJson('/api/v1/notifications/batch', ['notifications' => array_fill(0, 5, [
        'channel' => 'email', 'recipient' => 'a@example.com', 'content' => 'Sale',
    ])])->assertAccepted();

    expect(Notification::query()->whereNotNull('trace_context')->count())->toBe(2)
        ->and(Notification::query()->whereNull('trace_context')->count())->toBe(3);

    // An untraced notification is delivered without starting a trace of its own.
    $this->exporter->getStorage()->exchangeArray([]);
    providerResponds();
    deliver(Notification::query()->whereNull('trace_context')->firstOrFail());

    expect($this->exporter->getSpans())->toBe([]);
    Http::assertSent(fn (Request $outgoing): bool => $outgoing->header('traceparent') === []);
});

it('marks the span as failed when the delivery throws', function (): void {
    $id = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello',
    ])->json('data.id');
    Http::fake(['provider.test/*' => fn (): never => throw new LogicException('bug')]);

    expect(fn (): DeliveryOutcome => deliver(Notification::query()->findOrFail($id)))->toThrow(LogicException::class);

    $delivery = spans($this->exporter)['notification.deliver'];
    expect($delivery->getStatus()->getCode())->toBe('Error')
        ->and($delivery->getEvents()[0]->getName())->toBe('exception');
});

it('records nothing when tracing is disabled', function (): void {
    $this->app->forgetInstance(Tracing::class);

    $id = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello',
    ])->assertCreated()->json('data.id');

    expect(Notification::query()->findOrFail($id)->trace_context)->toBeNull()
        ->and($this->exporter->getSpans())->toBe([]);
});
