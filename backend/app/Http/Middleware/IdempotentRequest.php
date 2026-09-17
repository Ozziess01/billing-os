<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key на мутирующих финансовых запросах. Один ключ в организации = одна операция:
 * повтор того же запроса отдаёт сохранённый ответ, тот же ключ с другим телом - 409,
 * параллельный повтор, пока первый ещё выполняется, - тоже 409.
 */
class IdempotentRequest
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            return $next($request);
        }

        if (strlen($key) > 120) {
            return response()->json(['message' => 'Idempotency-Key длиннее 120 символов.'], 400);
        }

        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $organizationId = $this->current->id();

        // истёкший ключ можно использовать заново
        IdempotencyKey::query()->where('organization_id', $organizationId)->where('key', $key)->where('expires_at', '<', now())->delete();

        try {
            $record = DB::transaction(fn () => IdempotencyKey::create([
                'organization_id' => $organizationId,
                'key' => $key,
                'request_hash' => $hash,
                'status' => 'processing',
                'expires_at' => now()->addHours((int) config('billing.idempotency_ttl_hours', 24)),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->replay($organizationId, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // запрос не дошёл до ответа - ключ свободен, клиент может повторить
            $record->delete();

            throw $e;
        }

        // 5xx не запоминаем: это наш сбой, повтор должен пройти заново
        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
        ])->save();

        return $response;
    }

    private function replay(int $organizationId, string $key, string $hash): Response
    {
        $record = IdempotencyKey::query()->where('organization_id', $organizationId)->where('key', $key)->first();

        if (! $record) {
            return response()->json(['message' => 'Запрос с этим Idempotency-Key ещё выполняется.'], 409);
        }

        if ($record->request_hash !== $hash) {
            return response()->json(['message' => 'Idempotency-Key уже использован для другого запроса.'], 409);
        }

        if ($record->status !== 'completed') {
            return response()->json(['message' => 'Запрос с этим Idempotency-Key ещё выполняется.'], 409);
        }

        // тело отдаём байт в байт: {} и [] после json_decode неразличимы, а клиенту это важно
        return response((string) $record->response_body, (int) $record->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
