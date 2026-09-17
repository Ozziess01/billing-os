<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // организации живут вне контекста X-Organization: это выбор контекста, а не работа в нём
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);
        Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
        Route::patch('organizations/{organization}', [OrganizationController::class, 'update']);
        Route::get('organizations/{organization}/members', [OrganizationController::class, 'members']);
        Route::post('organizations/{organization}/members', [OrganizationController::class, 'addMember']);
        Route::patch('organizations/{organization}/members/{member}', [OrganizationController::class, 'updateMember']);
        Route::delete('organizations/{organization}/members/{member}', [OrganizationController::class, 'removeMember']);
        Route::post('organizations/{organization}/transfer', [OrganizationController::class, 'transfer']);

        Route::middleware(ResolveOrganization::class)->group(function () {
            Route::get('dashboard', DashboardController::class);

            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('prices', PriceController::class);

            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::post('subscriptions', [SubscriptionController::class, 'store']);
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
            Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
            Route::post('subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
        });
    });
});
