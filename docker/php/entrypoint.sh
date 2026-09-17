#!/bin/sh
set -e

role="${CONTAINER_ROLE:-app}"

# через приложение, чтобы учитывались и .env, и переменные контейнера
wait_for_db() {
    until php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->make("db")->connection()->getPdo();
    ' >/dev/null 2>&1; do
        echo "waiting for postgres..."
        sleep 2
    done
}

# зависимости ставит только app, остальные ждут, пока он закончит
wait_for_app() {
    until [ -f vendor/autoload.php ] && php artisan --version >/dev/null 2>&1; do
        echo "waiting for app..."
        sleep 3
    done
}

# production-образ: vendor уже внутри, .env приходит переменными окружения,
# конфиг/маршруты/события кешируются один раз при старте
production() {
    [ "${APP_ENV:-local}" = "production" ] && [ ! -f .env ]
}

case "$role" in
    app)
        if production; then
            wait_for_db
            php artisan migrate --force --no-interaction
            php artisan optimize --no-interaction
            exec php-fpm
        fi

        [ -f .env ] || cp .env.example .env

        composer install --no-interaction --prefer-dist --no-progress

        if ! grep -q '^APP_KEY=base64:' .env; then
            php artisan key:generate --force --no-interaction
        fi

        wait_for_db
        php artisan migrate --force --no-interaction
        php artisan optimize:clear -q
        exec php-fpm
        ;;
    worker)
        wait_for_app
        wait_for_db
        php artisan schedule:work >/dev/null 2>&1 &
        exec php artisan queue:work redis --queue=billing,default --sleep=1 --tries=3 --max-time=3600
        ;;
    websocket)
        wait_for_app
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    *)
        exec "$@"
        ;;
esac
