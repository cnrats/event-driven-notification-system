<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Middleware;

use App\Modules\Observability\Tracing\Tracing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * One server span per API request. Continues the caller's trace when the
 * request carries a `traceparent` header, otherwise starts a new one.
 *
 * The position in the trace is put into the request context so that the
 * notifications created by this request can store it (see RequestContext).
 */
final readonly class TraceRequest
{
    public function __construct(private Tracing $tracing) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $path = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');
        $incoming = $request->headers->get('traceparent');

        return $this->tracing->span(
            $request->method().' '.$path,
            [
                'http.request.method' => $request->method(),
                'http.route' => $path,
                'correlation_id' => $this->correlationId(),
            ],
            function (SpanInterface $span) use ($request, $next): Response {
                Context::addHidden('traceparent', $this->tracing->traceparent());

                $response = $next($request);
                $span->setAttribute('http.response.status_code', $response->getStatusCode());

                return $response;
            },
            SpanKind::KIND_SERVER,
            is_string($incoming) && $incoming !== '' ? $incoming : null,
        );
    }

    /**
     * After the response has been sent, so exporting never delays the client.
     */
    public function terminate(): void
    {
        $this->tracing->flush();
    }

    private function correlationId(): ?string
    {
        $correlationId = Context::get('correlation_id');

        return is_string($correlationId) ? $correlationId : null;
    }
}
