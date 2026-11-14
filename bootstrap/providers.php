<?php

declare(strict_types=1);

use App\Modules\Observability\ObservabilityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ObservabilityServiceProvider::class,
];
