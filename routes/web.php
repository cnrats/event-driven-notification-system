<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'api' => url('/api/v1'),
    'docs' => 'http://localhost:8081',
    'horizon' => url('/horizon'),
]));
