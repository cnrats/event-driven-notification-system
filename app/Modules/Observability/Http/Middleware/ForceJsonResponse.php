<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API only speaks JSON. Without this, a client that forgets the Accept
 * header gets an HTML redirect on validation errors.
 */
final class ForceJsonResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
