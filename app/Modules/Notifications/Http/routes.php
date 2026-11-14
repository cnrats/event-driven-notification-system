<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\BatchController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('notifications', [NotificationController::class, 'store']);
Route::post('notifications/batch', [BatchController::class, 'store']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
Route::post('notifications/{id}/cancel', [NotificationController::class, 'cancel'])->whereUuid('id');
Route::post('notifications/{id}/retry', [NotificationController::class, 'retry'])->whereUuid('id');

Route::get('batches/{id}', [BatchController::class, 'show'])->whereUuid('id');
Route::post('batches/{id}/cancel', [BatchController::class, 'cancel'])->whereUuid('id');
