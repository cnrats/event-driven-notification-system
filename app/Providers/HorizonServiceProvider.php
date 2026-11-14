<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * The dashboard is open in this demo. In production this gate would
     * check the authenticated user.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (mixed $user = null): bool => true);
    }
}
