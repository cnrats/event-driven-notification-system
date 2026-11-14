<?php

declare(strict_types=1);

use App\Modules\Templates\Http\TemplateController;
use Illuminate\Support\Facades\Route;

Route::post('templates', [TemplateController::class, 'store']);
Route::get('templates', [TemplateController::class, 'index']);
Route::get('templates/{name}', [TemplateController::class, 'show']);
