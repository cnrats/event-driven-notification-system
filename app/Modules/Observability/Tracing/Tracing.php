<?php

declare(strict_types=1);

namespace App\Modules\Observability\Tracing;

use Closure;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

/**
 * Distributed tracing with OpenTelemetry, reduced to what this system needs.
 *
 * A notification crosses a process boundary and a delay: the API accepts it,
 * a worker delivers it seconds or hours later. The link between the two is
 * the W3C `traceparent` value, stored on the notification row when it is
 * accepted and picked up again by the worker. The same value is sent to the
 * provider as a header, so the trace can continue on their side.
 *
 * When tracing is disabled the tracer is a no-op and nothing is recorded.
 */
final class Tracing
{
    private const float FLUSH_EVERY_SECONDS = 2.0;

    private float $flushedAt = 0.0;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly ?TracerProviderInterface $provider = null,
    ) {}

    /**
     * Run $work inside a new span and return its result.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  array<non-empty-string, bool|int|float|string|null>  $attributes
     * @param  Closure(SpanInterface): T  $work
     * @param  SpanKind::KIND_*  $kind
     * @param  string|null  $parent  a traceparent value to continue instead of the current span
     * @return T
     */
    public function span(string $name, array $attributes, Closure $work, int $kind = SpanKind::KIND_INTERNAL, ?string $parent = null): mixed
    {
        $context = $parent === null
            ? Context::getCurrent()
            : TraceContextPropagator::getInstance()->extract(['traceparent' => $parent]);

        $span = $this->tracer->spanBuilder($name)
            ->setSpanKind($kind)
            ->setParent($context)
            ->setAttributes($attributes)
            ->startSpan();

        $scope = $span->activate();

        try {
            return $work($span);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Like span(), but only when a trace is already in progress. Used inside
     * the delivery pipeline so that untraced notifications (most of a large
     * batch) do not each start a trace of their own.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  array<non-empty-string, bool|int|float|string|null>  $attributes
     * @param  Closure(SpanInterface): T  $work
     * @param  SpanKind::KIND_*  $kind
     * @return T
     */
    public function childSpan(string $name, array $attributes, Closure $work, int $kind = SpanKind::KIND_INTERNAL): mixed
    {
        $current = Span::getCurrent();

        return $current->getContext()->isValid()
            ? $this->span($name, $attributes, $work, $kind)
            : $work($current);
    }

    /**
     * The current position in the trace as a W3C traceparent value, or null
     * when nothing is being traced.
     */
    public function traceparent(): ?string
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        $traceparent = is_array($carrier) ? ($carrier['traceparent'] ?? null) : null;

        return is_string($traceparent) ? $traceparent : null;
    }

    /**
     * Send finished spans to the collector. Never throws: tracing must not
     * be able to break a request or a delivery.
     */
    public function flush(): void
    {
        $this->flushedAt = microtime(true);

        try {
            $this->provider?->forceFlush();
        } catch (Throwable) {
            // The collector is unreachable; the spans are dropped.
        }
    }

    /**
     * For long-running workers: flush at most every couple of seconds
     * instead of once per job.
     */
    public function flushIfDue(): void
    {
        if (microtime(true) - $this->flushedAt >= self::FLUSH_EVERY_SECONDS) {
            $this->flush();
        }
    }
}
