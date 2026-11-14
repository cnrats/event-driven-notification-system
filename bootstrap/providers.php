<?php

declare(strict_types=1);

use App\Modules\Delivery\DeliveryServiceProvider;
use App\Modules\Observability\ObservabilityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    ObservabilityServiceProvider::class,
    DeliveryServiceProvider::class,
];
