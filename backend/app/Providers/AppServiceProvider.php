<?php

namespace App\Providers;

use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // одна организация на запрос; в очереди и консоли её заполняют явно
        $this->app->scoped(CurrentOrganization::class);
    }

    public function boot(): void
    {
        // ленивая загрузка и обращение к несуществующим атрибутам - ошибки, а не сюрпризы в проде
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
