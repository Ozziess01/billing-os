<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Счётчики для /metrics: класс статуса и латентность по бакетам. Redis недоступен - тихо пропускаем. */
class CountRequests
{
    public const KEY_STATUS = 'metrics:http:status';

    public const KEY_BUCKETS = 'metrics:http:buckets';

    public const KEY_SUM = 'metrics:http:sum';

    public const BUCKETS = [0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            $elapsed = microtime(true) - $start;
            $class = intdiv($response->getStatusCode(), 100).'xx';
            $bucket = 'inf';
            foreach (self::BUCKETS as $le) {
                if ($elapsed <= $le) {
                    $bucket = (string) $le;
                    break;
                }
            }

            Redis::connection()->pipeline(function ($pipe) use ($class, $bucket, $elapsed) {
                $pipe->hincrby(self::KEY_STATUS, $class, 1);
                $pipe->hincrby(self::KEY_BUCKETS, $bucket, 1);
                $pipe->incrbyfloat(self::KEY_SUM, round($elapsed, 4));
            });
        } catch (Throwable) {
            // метрики не должны ломать запрос
        }

        return $response;
    }
}
