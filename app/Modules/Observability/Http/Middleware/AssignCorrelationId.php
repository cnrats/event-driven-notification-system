<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID: the caller's X-Correlation-ID when it
 * sends a sane one, otherwise a new UUID.
 *
 * The ID goes into Laravel's Context, which adds it to every log line and
 * travels with queued jobs, and it is stored on each notification so the
 * worker that delivers it later logs under the same ID.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Correlation-ID';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $given = $request->headers->get(self::HEADER);

        $correlationId = is_string($given) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $given) === 1
            ? $given
            : (string) Str::uuid();

        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
