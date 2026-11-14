<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use App\Modules\Notifications\Contracts\RendersTemplates;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: the per-request template cache must not outlive the request.
        $this->app->scoped(RendersTemplates::class, TemplateRenderer::class);
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
