<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** Организация с владельцем, как её создаёт API. */
function organization(?User $owner = null, string $name = 'Acme'): Organization
{
    return app(OrganizationService::class)->create($owner ?? User::factory()->create(), $name);
}

function member(Organization $organization, Role $role): User
{
    $user = User::factory()->create();
    $organization->members()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

/** Залогиниться и работать в контексте организации (заголовок X-Organization на все запросы теста). */
function actingIn(Organization $organization, User|Role $as = Role::Owner): User
{
    $user = $as instanceof User ? $as : ($as === Role::Owner ? $organization->owner : member($organization, $as));

    Sanctum::actingAs($user);
    // заголовки предыдущих запросов (в т.ч. Idempotency-Key) не должны утекать дальше
    test()->flushHeaders()->withHeader('X-Organization', (string) $organization->id);

    return $user;
}

function customer(Organization $organization, array $attributes = []): Customer
{
    return Customer::factory()->for($organization)->create($attributes);
}

function product(Organization $organization, array $attributes = []): Product
{
    return Product::factory()->for($organization)->create($attributes);
}

function price(Organization $organization, array $attributes = [], ?Product $product = null): Price
{
    return Price::factory()
        ->for($product ?? product($organization))
        ->create(['organization_id' => $organization->id, ...$attributes]);
}

/**
 * Ожидаемая ошибка базы (CHECK, триггер): выполняем внутри savepoint,
 * иначе PostgreSQL отменит транзакцию теста целиком.
 */
function dbFails(Closure $statement, string $message): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, $message);
}

/** Открытый инвойс на одну позицию через API от имени текущего пользователя. */
function openInvoice(Organization $organization, int $amount = 1999): string
{
    $price = price($organization, ['unit_amount' => $amount]);
    $id = test()->postJson('/api/v1/invoices', ['customer_id' => customer($organization)->id, 'items' => [['price_id' => $price->id]]])->json('data.id');
    test()->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();

    return $id;
}
