<?php

declare(strict_types=1);

use App\Modules\Notifications\Application\Exceptions\IdempotencyConflict;
use App\Modules\Notifications\Application\Exceptions\InvalidNotification;
use App\Modules\Observability\Http\Middleware\AssignCorrelationId;
use App\Modules\Observability\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API routes are registered by each module's service provider.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            AssignCorrelationId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Client mistakes, not application errors: do not report them.
        $exceptions->dontReport([InvalidNotification::class, IdempotencyConflict::class]);

        $exceptions->render(fn (InvalidNotification $e): JsonResponse => response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors,
        ], 422));

        $exceptions->render(fn (IdempotencyConflict $e): JsonResponse => response()->json([
            'message' => $e->getMessage(),
        ], 409));

        $exceptions->render(fn (NotFoundHttpException $e, Request $request): ?JsonResponse => $request->is('api/*')
            ? response()->json(['message' => 'Resource not found.'], 404)
            : null);
    })->create();
