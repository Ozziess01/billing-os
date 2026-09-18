<?php

return [

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'EUR'),

    // срок оплаты открытого инвойса, дней
    'invoice_due_days' => (int) env('BILLING_INVOICE_DUE_DAYS', 7),

    // ключи идемпотентности живут сутки: повтор позже - уже новый запрос
    'idempotency_ttl_hours' => (int) env('BILLING_IDEMPOTENCY_TTL_HOURS', 24),

    // автосписание: через сколько дней повторять после неудачи и что делать, когда попытки кончились
    'dunning' => [
        'retry_after_days' => [1, 3, 7],
        'cancel_after_retries' => (bool) env('BILLING_CANCEL_AFTER_RETRIES', true),
    ],

    // журнал действий хранится год
    'activity_retention_days' => (int) env('BILLING_ACTIVITY_RETENTION_DAYS', 365),

    // ссылка в клиентский портал живёт сутки
    'portal_session_hours' => (int) env('BILLING_PORTAL_SESSION_HOURS', 24),

    'default_provider' => env('BILLING_PROVIDER', 'fake'),

    'providers' => [
        'fake' => [
            // куда fake-провайдер шлёт вебхуки; внутри compose это nginx, снаружи - APP_URL
            'webhook_url' => env('FAKE_WEBHOOK_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/webhooks/fake'),
            'async_delay' => (int) env('FAKE_ASYNC_DELAY', 3),
        ],
    ],

    /*
     * Валюты, которые принимает API, и число знаков после запятой (ISO 4217).
     * Суммы везде хранятся в минорных единицах: 19.99 EUR = 1999, 1000 JPY = 1000.
     */
    'currencies' => [
        'EUR' => 2,
        'USD' => 2,
        'GBP' => 2,
        'CHF' => 2,
        'PLN' => 2,
        'CZK' => 2,
        'SEK' => 2,
        'NOK' => 2,
        'DKK' => 2,
        'CAD' => 2,
        'AUD' => 2,
        'RUB' => 2,
        'TRY' => 2,
        'INR' => 2,
        'BRL' => 2,
        'MXN' => 2,
        'JPY' => 0,
        'KRW' => 0,
        'HUF' => 2,
        'KWD' => 3,
        'BHD' => 3,
    ],

];
