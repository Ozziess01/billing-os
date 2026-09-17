<?php

use App\Billing\CurrencyMismatch;
use App\Billing\InvalidTransition;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // организация запроса должна быть известна до того, как маршруты начнут искать модели по id
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveOrganization::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // нарушение доменных правил - это конфликт состояния, а не ошибка сервера
        $exceptions->render(fn (InvalidTransition|CurrencyMismatch $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
        ], 409));
    })->create();
