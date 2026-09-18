<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\AuthenticatePortal;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

// документация лежит вне версии: одна страница на всё API
Route::get('openapi.yaml', [DocsController::class, 'spec']);
Route::get('docs', [DocsController::class, 'ui']);

Route::prefix('v1')->group(function () {
    // провайдер стучится сюда без токена: доверие только по подписи тела
    Route::post('webhooks/{provider}', [WebhookController::class, 'handle'])->middleware('throttle:120,1');

    // кабинет клиента: токен сессии портала вместо пользовательской учётки
    Route::prefix('portal')->middleware([AuthenticatePortal::class, 'throttle:120,1'])->group(function () {
        Route::get('session', [PortalController::class, 'session']);
        Route::patch('billing', [PortalController::class, 'updateBilling']);
        Route::get('subscriptions', [PortalController::class, 'subscriptions']);
        Route::post('subscriptions/{id}/cancel', [PortalController::class, 'cancelSubscription']);
        Route::get('invoices', [PortalController::class, 'invoices']);
        Route::get('invoices/{id}', [PortalController::class, 'invoice']);
        Route::get('invoices/{id}/export', [PortalController::class, 'export']);
        Route::post('invoices/{id}/pay', [PortalController::class, 'pay']);
        Route::get('payments', [PortalController::class, 'payments']);
    });

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    // пользовательский токен Sanctum или ключ интеграции организации
    Route::middleware('auth:sanctum,api-key')->group(function () {
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
            Route::post('customers/{customer}/portal-session', [CustomerController::class, 'portalSession']);

            Route::get('api-keys', [ApiKeyController::class, 'index']);
            Route::post('api-keys', [ApiKeyController::class, 'store']);
            Route::delete('api-keys/{key}', [ApiKeyController::class, 'destroy']);
            Route::apiResource('products', ProductController::class);
            Route::apiResource('prices', PriceController::class);

            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::post('subscriptions', [SubscriptionController::class, 'store']);
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
            Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
            Route::post('subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
            Route::post('subscriptions/{subscription}/invoice', [InvoiceController::class, 'forSubscription'])->middleware(IdempotentRequest::class);
            Route::post('subscriptions/{subscription}/coupon', [CouponController::class, 'apply']);
            Route::get('subscriptions/{subscription}/usage', [UsageController::class, 'summary']);

            Route::get('usage', [UsageController::class, 'index']);
            Route::post('usage', [UsageController::class, 'store'])->middleware('throttle:600,1');

            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::get('coupons/{coupon}', [CouponController::class, 'show']);
            Route::patch('coupons/{coupon}', [CouponController::class, 'update']);

            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store'])->middleware(IdempotentRequest::class);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
            Route::post('invoices/{invoice}/items', [InvoiceController::class, 'addItem']);
            Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'removeItem']);
            Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])->middleware(IdempotentRequest::class);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
            Route::post('invoices/{invoice}/uncollectible', [InvoiceController::class, 'uncollectible']);

            // финансовые POST принимают Idempotency-Key: повтор с тем же ключом отдаёт первый ответ
            Route::get('payments', [PaymentController::class, 'index']);
            Route::post('payments', [PaymentController::class, 'store'])->middleware([IdempotentRequest::class, 'throttle:60,1']);
            Route::get('payments/{payment}', [PaymentController::class, 'show']);
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware([IdempotentRequest::class, 'throttle:60,1']);
            Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel']);
            Route::get('refunds', [PaymentController::class, 'refunds']);

            Route::get('ledger/accounts', [LedgerController::class, 'accounts']);
            Route::get('ledger/transactions', [LedgerController::class, 'transactions']);

            Route::get('activity', [ActivityController::class, 'index']);

            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
            Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
            Route::patch('notifications/preferences', [NotificationController::class, 'updatePreferences']);

            Route::get('webhooks/events', [WebhookController::class, 'events']);
            Route::post('providers/fake/payments/{providerPaymentId}/confirm', [WebhookController::class, 'confirmFakePayment']);
        });
    });
});
