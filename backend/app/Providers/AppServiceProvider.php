<?php

namespace App\Providers;

use App\Auth\ApiKeyGuard;
use App\Tenancy\CurrentCustomer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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
    }
}
