<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http;

use App\Modules\Notifications\Application\RequestMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Reads request-scoped identifiers for the application layer.
 */
final class RequestContext
{
    public static function idempotencyKey(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key');

        return $key === null || $key === '' ? null : $key;
    }

    public static function meta(): RequestMeta
    {
        $correlationId = Context::get('correlation_id');
        $traceContext = Context::getHidden('traceparent');

        return new RequestMeta(
            correlationId: is_string($correlationId) ? $correlationId : null,
            traceContext: is_string($traceContext) ? $traceContext : null,
        );
    }
}
