<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventStatus;
use App\Http\Middleware\CountRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Prometheus text exposition. Endpoint закрыт METRICS_TOKEN: без токена в конфиге
 * его нет (404), с неверным - 401. Публичным он не бывает.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('observability.metrics_token');

        if ($token === '') {
            abort(404);
        }

        $given = $request->bearerToken() ?? $request->query('token', '');
        if (! is_string($given) || ! hash_equals($token, $given)) {
            abort(401, 'metrics token required');
        }

        $lines = [];
        $emit = function (string $type, string $name, string $help, array $samples) use (&$lines) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} {$type}";
            foreach ($samples as [$labels, $value]) {
                $lines[] = $name.self::labels($labels).' '.$value;
            }
        };
        $byStatus = fn (string $table, array $cases) => array_map(
            fn ($case) => [['status' => $case->value], (int) (DB::table($table)->where('status', $case->value)->count())],
            $cases,
        );

        // HTTP: счётчики по классу статуса и гистограмма латентности из Redis
        $http = Redis::hgetall(CountRequests::KEY_STATUS) ?: [];
        $emit('counter', 'billingos_http_requests_total', 'HTTP requests by status class', collect($http)->map(fn ($v, $k) => [['status' => $k], (int) $v])->values()->all());

        $buckets = Redis::hgetall(CountRequests::KEY_BUCKETS) ?: [];
        $lines[] = '# HELP billingos_http_request_duration_seconds Request latency';
        $lines[] = '# TYPE billingos_http_request_duration_seconds histogram';
        $cumulative = 0;
        foreach (CountRequests::BUCKETS as $le) {
            $cumulative += (int) ($buckets[(string) $le] ?? 0);
            $lines[] = 'billingos_http_request_duration_seconds_bucket{le="'.$le.'"} '.$cumulative;
        }
        $cumulative += (int) ($buckets['inf'] ?? 0);
        $lines[] = 'billingos_http_request_duration_seconds_bucket{le="+Inf"} '.$cumulative;
        $lines[] = 'billingos_http_request_duration_seconds_sum '.(float) (Redis::get(CountRequests::KEY_SUM) ?: 0);
        $lines[] = 'billingos_http_request_duration_seconds_count '.$cumulative;

        $emit('gauge', 'billingos_queue_size', 'Jobs waiting in queue', array_map(fn ($q) => [['queue' => $q], (int) Redis::llen("queues:{$q}")], ['default', 'billing']));
        $emit('gauge', 'billingos_failed_jobs', 'Jobs in failed_jobs table', [[[], (int) DB::table('failed_jobs')->count()]]);

        $emit('gauge', 'billingos_invoices', 'Invoices by status', $byStatus('invoices', InvoiceStatus::cases()));
        $emit('gauge', 'billingos_subscriptions', 'Subscriptions by status', $byStatus('subscriptions', SubscriptionStatus::cases()));
        $emit('gauge', 'billingos_webhook_events', 'Webhook events by status', $byStatus('webhook_events', WebhookEventStatus::cases()));

        $since = now()->subDay();
        $payments = DB::table('payments')->where('created_at', '>=', $since)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $emit('gauge', 'billingos_payments_24h', 'Payment attempts in the last 24 hours by status', array_map(
            fn (PaymentStatus $s) => [['status' => $s->value], (int) ($payments[$s->value] ?? 0)],
            PaymentStatus::cases(),
        ));
        $emit('gauge', 'billingos_invoices_overdue', 'Open invoices past due date', [[[], (int) DB::table('invoices')->where('status', 'open')->where('due_at', '<', now())->count()]]);
        $emit('gauge', 'billingos_organizations', 'Organizations', [[[], (int) DB::table('organizations')->count()]]);

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k.'="'.addcslashes((string) $v, '"\\').'"';
        }

        return '{'.implode(',', $parts).'}';
    }
}
