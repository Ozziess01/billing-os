<?php

use App\Billing\CurrencyMismatch;
use App\Billing\InvalidTransition;
use App\Http\Middleware\CountRequests;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\SecureHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Broadcast;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // /api/broadcasting/auth: авторизация приватных каналов по тому же Bearer-токену
        then: fn () => Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']]),
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // заголовки безопасности - на все ответы; счётчики /metrics - на API
        $middleware->append(SecureHeaders::class);
        $middleware->api(prepend: [CountRequests::class]);

        // API без сессий и страниц входа: гостю всегда отвечаем 401 JSON, а не редиректом
        $middleware->redirectGuestsTo(fn () => null);

        // организация запроса должна быть известна до биндинга моделей и до проверки Idempotency-Key
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveOrganization::class);
        $middleware->appendToPriorityList(ResolveOrganization::class, IdempotentRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry получает исключения только при заданном SENTRY_LARAVEL_DSN
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // нарушение доменных правил - это конфликт состояния, а не ошибка сервера
        $exceptions->render(fn (InvalidTransition|CurrencyMismatch $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
        ], 409));

        // стандартный текст 404 раскрывает имя класса модели и id - наружу отдаём общий
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Не найдено.'], 404);
            }
        });
    })->create();
