<?php

return [
    // токен для GET /metrics (Prometheus). Пусто - endpoint выключен (404)
    'metrics_token' => env('METRICS_TOKEN'),

    // сколько секунд тишины от планировщика считать нормой в /health/deep
    'scheduler_max_age' => (int) env('SCHEDULER_MAX_AGE', 180),
];
