<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Subscription;

it('creates, reads, updates and deletes customers inside the organization', function () {
    $organization = organization();
    actingIn($organization);

    $id = $this->postJson('/api/v1/customers', [
        'name' => 'Globex', 'email' => 'billing@globex.test', 'external_id' => 'cus_42',
        'metadata' => ['plan' => 'pro', 'seats' => 5],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Globex')
        ->assertJsonPath('data.metadata.seats', 5)
        ->json('data.id');

    expect(strlen($id))->toBe(26)
        ->and(Customer::find($id)->organization_id)->toBe($organization->id);

    $this->patchJson("/api/v1/customers/{$id}", ['name' => 'Globex Corp', 'metadata' => ['plan' => 'team']])
        ->assertOk()->assertJsonPath('data.name', 'Globex Corp')->assertJsonPath('data.metadata', ['plan' => 'team']);

    $this->getJson('/api/v1/customers?q=globex')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customers?q=cus_42')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customers?q=nothing')->assertOk()->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/customers/{$id}")->assertNoContent();
    $this->getJson("/api/v1/customers/{$id}")->assertNotFound();
});

it('keeps external ids unique per organization but not across organizations', function () {
    $organization = organization();
    customer(organization(), ['external_id' => 'cus_1']);
    actingIn($organization);

    $this->postJson('/api/v1/customers', ['name' => 'A', 'external_id' => 'cus_1'])->assertCreated();
    $this->postJson('/api/v1/customers', ['name' => 'B', 'external_id' => 'cus_1'])
        ->assertUnprocessable()->assertJsonValidationErrors('external_id');
});

it('validates customer payloads', function () {
    actingIn(organization());

    $this->postJson('/api/v1/customers', ['email' => 'not-an-email'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);

    $this->postJson('/api/v1/customers', ['name' => 'X', 'metadata' => ['nested' => ['deep' => true]]])
        ->assertUnprocessable()->assertJsonValidationErrors('metadata');

    $this->postJson('/api/v1/customers', ['name' => 'X', 'metadata' => array_fill_keys(range(1, 51), 'v')])
        ->assertUnprocessable()->assertJsonValidationErrors('metadata');
});

it('never shows customers of another organization', function () {
    $mine = organization();
    $theirs = organization();
    $foreign = customer($theirs);
    customer($mine);
    actingIn($mine);

    $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
    $this->patchJson("/api/v1/customers/{$foreign->id}", ['name' => 'pwned'])->assertNotFound();
    $this->deleteJson("/api/v1/customers/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('pwned');
});

it('applies roles: viewer reads, developer writes, admin deletes', function () {
    $organization = organization();
    $existing = customer($organization);

    actingIn($organization, Role::Viewer);
    $this->getJson('/api/v1/customers')->assertOk();
    $this->postJson('/api/v1/customers', ['name' => 'Nope'])->assertForbidden();
    $this->patchJson("/api/v1/customers/{$existing->id}", ['name' => 'Nope'])->assertForbidden();

    actingIn($organization, Role::Developer);
    $created = $this->postJson('/api/v1/customers', ['name' => 'Dev made'])->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/customers/{$created}", ['name' => 'Dev edited'])->assertOk();
    $this->deleteJson("/api/v1/customers/{$created}")->assertForbidden();

    actingIn($organization, Role::Admin);
    $this->deleteJson("/api/v1/customers/{$created}")->assertNoContent();
});

it('refuses to delete a customer with subscriptions', function () {
    $organization = organization();
    $customer = customer($organization);
    Subscription::factory()->for($customer)->create();
    actingIn($organization);

    $this->deleteJson("/api/v1/customers/{$customer->id}")->assertUnprocessable();
    expect($customer->fresh())->not->toBeNull();
});
