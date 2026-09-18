<?php

namespace App\Providers;

use App\Auth\ApiKeyGuard;
use App\Tenancy\CurrentCustomer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // одна организация (и один клиент портала) на запрос; в очереди и консоли их заполняют явно
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(CurrentCustomer::class);
    }

    public function boot(): void
    {
        // ленивая загрузка и обращение к несуществующим атрибутам - ошибки, а не сюрпризы в проде
        Model::shouldBeStrict(! $this->app->isProduction());

        Auth::viaRequest('api-key', new ApiKeyGuard);

        $this->rateLimits();
    }

    /**
     * Именованные лимиты. Безымянный throttle:N,1 считает все маршруты с одним
     * ключом (пользователь или ip) в общий счётчик: поток usage-событий забирал
     * бы лимит платежей того же пользователя, а вебхуки провайдера - лимит логина.
     */
    private function rateLimits(): void
    {
        $byUser = fn (Request $request) => $request->user()?->getAuthIdentifier() ?: $request->ip();

        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(60)->by('payments:'.$byUser($request)));
        RateLimiter::for('usage', fn (Request $request) => Limit::perMinute(600)->by('usage:'.$byUser($request)));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(10)->by('register:'.$request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by('login-attempts:'.$request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by('webhooks:'.$request->route('provider').':'.$request->ip()));
        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(120)->by('portal:'.sha1((string) $request->bearerToken()).':'.$request->ip()));
    }
}
