<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Три уровня: live - процесс отвечает (без зависимостей, для restart-политик);
 * ready - можно ли принимать трафик (БД и Redis); deep - вся система: очереди,
 * планировщик, сокет, зависшие платежи. Временная проблема с Redis не должна
 * заставлять оркестратор перезапускать здоровый php-fpm.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::ping()),
        ];

        $ok = ! in_array('fail', array_column($checks, 'status'), true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }

    public function deep(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::ping()),
            'queue' => $this->queue(),
            'scheduler' => $this->heartbeat('scheduler', (int) config('observability.scheduler_max_age', 180)),
            'websocket' => $this->check(function () {
                $socket = @fsockopen((string) config('broadcasting.connections.reverb.options.host', 'websocket'), (int) config('broadcasting.connections.reverb.options.port', 8080), $errno, $errstr, 2);
                if (! $socket) {
                    throw new RuntimeException($errstr ?: 'unreachable');
                }
                fclose($socket);
            }),
            'payments' => $this->stuckPayments(),
        ];

        $statuses = array_column($checks, 'status');
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'degraded' : 'ok');

        return response()->json(['status' => $status, 'checks' => $checks], $status === 'fail' ? 503 : 200);
    }

    /** Возраст самого старого job в очередях: очередь стоит - предупреждение, потом отказ. */
    private function queue(): array
    {
        try {
            $oldest = null;
            $sizes = [];
            foreach (['default', 'billing'] as $queue) {
                $sizes[$queue] = (int) Redis::llen("queues:{$queue}");
                $head = Redis::lindex("queues:{$queue}", 0);
                if ($head && ($payload = json_decode($head, true)) && isset($payload['pushedAt'])) {
                    $oldest = max($oldest ?? 0, time() - (int) $payload['pushedAt']);
                }
            }

            $status = $oldest === null ? 'ok' : ($oldest > 600 ? 'fail' : ($oldest > 120 ? 'warn' : 'ok'));

            return ['status' => $status, 'sizes' => $sizes, 'oldest_job_age' => $oldest];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /** Платежи «в полёте» дольше часа - провайдер молчит или вебхуки не доходят. */
    private function stuckPayments(): array
    {
        try {
            $stuck = (int) DB::table('payments')->whereIn('status', ['pending', 'processing'])->where('created_at', '<', now()->subHour())->count();

            return ['status' => $stuck > 0 ? 'warn' : 'ok', 'stuck' => $stuck];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    private function heartbeat(string $name, int $maxAgeSeconds): array
    {
        try {
            $at = Cache::get("heartbeat:{$name}");
            if ($at === null) {
                return ['status' => 'warn', 'last_seen' => null];
            }
            $age = time() - (int) $at;

            return ['status' => $age > $maxAgeSeconds ? 'fail' : 'ok', 'age' => $age];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    private function check(callable $probe): array
    {
        $start = microtime(true);
        try {
            $probe();

            return ['status' => 'ok', 'ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
