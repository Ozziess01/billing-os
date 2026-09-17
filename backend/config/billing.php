<?php

return [

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'EUR'),

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
