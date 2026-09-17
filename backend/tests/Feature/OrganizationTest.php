<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates an organization and makes the creator its owner', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $this->postJson('/api/v1/organizations', ['name' => 'Acme Billing', 'default_currency' => 'usd'])
        ->assertUnprocessable(); // валюта - строго в верхнем регистре из списка

    $id = $this->postJson('/api/v1/organizations', ['name' => 'Acme Billing', 'default_currency' => 'USD'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme-billing')
        ->assertJsonPath('data.default_currency', 'USD')
        ->assertJsonPath('data.members_count', 1)
        ->json('data.id');

    expect($user->fresh()->roleIn(Organization::find($id)))->toBe(Role::Owner);

    // одноимённая организация получает другой slug
    $this->postJson('/api/v1/organizations', ['name' => 'Acme Billing'])
        ->assertCreated()->assertJsonPath('data.slug', 'acme-billing-2');
});

it('manages members with roles', function () {
    $organization = organization();
    Sanctum::actingAs($organization->owner);
    $dev = User::factory()->create();

    $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => 'nobody@example.com', 'role' => 'developer'])
        ->assertUnprocessable();
    $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => $dev->email, 'role' => 'owner'])
        ->assertUnprocessable();

    $memberId = $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => $dev->email, 'role' => 'developer'])
        ->assertCreated()->assertJsonPath('data.role', 'developer')->json('data.id');

    $this->patchJson("/api/v1/organizations/{$organization->id}/members/{$memberId}", ['role' => 'admin'])
        ->assertOk()->assertJsonPath('data.role', 'admin');

    $this->getJson("/api/v1/organizations/{$organization->id}/members")->assertOk()->assertJsonCount(2, 'data');

    // владельца удалить нельзя
    $ownerMember = $organization->members()->where('role', Role::Owner)->first();
    $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$ownerMember->id}")->assertUnprocessable();

    $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$memberId}")->assertNoContent();
    expect($dev->fresh()->roleIn($organization))->toBeNull();
});

it('lets developers see but not manage members, and viewers leave on their own', function () {
    $organization = organization();
    $viewer = member($organization, Role::Viewer);
    $stranger = User::factory()->create();

    Sanctum::actingAs(member($organization, Role::Developer));
    $this->getJson("/api/v1/organizations/{$organization->id}/members")->assertOk();
    $this->postJson("/api/v1/organizations/{$organization->id}/members", ['email' => $stranger->email, 'role' => 'viewer'])->assertForbidden();
    $this->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Hijacked'])->assertForbidden();

    $membership = $organization->members()->where('user_id', $viewer->id)->first();
    Sanctum::actingAs($viewer);
    $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}")->assertNoContent();

    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    $this->getJson("/api/v1/organizations/{$organization->id}/members")->assertForbidden();
});

it('transfers ownership only by the owner and only to a member', function () {
    $organization = organization();
    $admin = member($organization, Role::Admin);
    $outsider = User::factory()->create();

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/organizations/{$organization->id}/transfer", ['user_id' => $admin->id])->assertForbidden();

    Sanctum::actingAs($organization->owner);
    $this->postJson("/api/v1/organizations/{$organization->id}/transfer", ['user_id' => $outsider->id])->assertUnprocessable();
    $this->postJson("/api/v1/organizations/{$organization->id}/transfer", ['user_id' => $admin->id])
        ->assertOk()->assertJsonPath('data.owner_id', $admin->id);

    expect($admin->roleIn($organization->fresh()))->toBe(Role::Owner)
        ->and($organization->owner->roleIn($organization))->toBe(Role::Admin);
});

it('resolves the organization from the header or the only membership', function () {
    $user = User::factory()->create();
    $first = organization($user, 'First');
    Sanctum::actingAs($user);

    // единственная организация - заголовок не нужен
    $this->getJson('/api/v1/customers')->assertOk();

    $second = organization($user, 'Second');
    $this->getJson('/api/v1/customers')->assertStatus(400);
    $this->withHeader('X-Organization', $second->slug)->getJson('/api/v1/customers')->assertOk();
    $this->withHeader('X-Organization', (string) $first->id)->getJson('/api/v1/customers')->assertOk();

    // чужая организация выглядит как несуществующая
    $foreign = organization();
    $this->withHeader('X-Organization', (string) $foreign->id)->getJson('/api/v1/customers')->assertNotFound();
    $this->withHeader('X-Organization', 'no-such-org')->getJson('/api/v1/customers')->assertNotFound();
});
