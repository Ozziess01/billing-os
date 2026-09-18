<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'BillingOS', 'api' => '/api/v1', 'docs' => '/api/docs']));

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/health/deep', [HealthController::class, 'deep']);
Route::get('/metrics', MetricsController::class)->middleware('throttle:60,1');
