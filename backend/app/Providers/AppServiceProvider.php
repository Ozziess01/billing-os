<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ленивая загрузка и обращение к несуществующим атрибутам - ошибки, а не сюрпризы в проде
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
