<?php

use App\Models\Price;

it('manages products and their prices', function () {
    $organization = organization();
    actingIn($organization);

    $productId = $this->postJson('/api/v1/products', ['name' => 'Pro plan', 'description' => 'Everything'])
        ->assertCreated()->assertJsonPath('data.active', true)->json('data.id');

    $priceId = $this->postJson('/api/v1/prices', [
        'product_id' => $productId, 'nickname' => 'Pro monthly',
        'currency' => 'EUR', 'unit_amount' => 1999, 'billing_interval' => 'month',
    ])->assertCreated()
        ->assertJsonPath('data.unit_amount', 1999)
        ->assertJsonPath('data.unit_amount_formatted', '19.99 EUR')
        ->assertJsonPath('data.interval_count', 1)
        ->json('data.id');

    $this->postJson('/api/v1/prices', [
        'product_id' => $productId, 'currency' => 'EUR', 'unit_amount' => 19900, 'billing_interval' => 'year',
    ])->assertCreated();

    $this->getJson("/api/v1/products/{$productId}")->assertOk()->assertJsonCount(2, 'data.prices');
    $this->getJson("/api/v1/prices?product_id={$productId}&active=1")->assertOk()->assertJsonCount(2, 'data');

    $this->patchJson("/api/v1/products/{$productId}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $this->getJson('/api/v1/products?active=0')->assertOk()->assertJsonCount(1, 'data');

    // у продукта с ценами удаление запрещено
    $this->deleteJson("/api/v1/products/{$productId}")->assertUnprocessable();
    $this->deleteJson("/api/v1/prices/{$priceId}")->assertNoContent();
});

it('stores money as integer minor units only', function () {
    $organization = organization();
    $product = product($organization);
    actingIn($organization);

    foreach ([19.99, '19.99', -100, 'abc', null] as $amount) {
        $this->postJson('/api/v1/prices', [
            'product_id' => $product->id, 'currency' => 'EUR', 'unit_amount' => $amount, 'billing_interval' => 'month',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_amount');
    }

    $this->postJson('/api/v1/prices', [
        'product_id' => $product->id, 'currency' => 'EUR', 'unit_amount' => 0, 'billing_interval' => 'month',
    ])->assertCreated();

    expect(Price::query()->first()->getRawOriginal('unit_amount'))->toBeInt();
});

it('validates currency and interval', function () {
    $organization = organization();
    $product = product($organization);
    actingIn($organization);

    $base = ['product_id' => $product->id, 'unit_amount' => 100, 'billing_interval' => 'month'];

    $this->postJson('/api/v1/prices', [...$base, 'currency' => 'eur'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->postJson('/api/v1/prices', [...$base, 'currency' => 'XYZ'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->postJson('/api/v1/prices', [...$base, 'currency' => 'EUR', 'billing_interval' => 'quarter'])
        ->assertUnprocessable()->assertJsonValidationErrors('billing_interval');
    $this->postJson('/api/v1/prices', [...$base, 'currency' => 'EUR', 'interval_count' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('interval_count');
    $this->postJson('/api/v1/prices', [...$base, 'currency' => 'JPY', 'billing_interval' => 'month', 'interval_count' => 3])
        ->assertCreated()->assertJsonPath('data.unit_amount_formatted', '100 JPY');
});

it('treats price amounts as immutable', function () {
    $organization = organization();
    $price = price($organization, ['unit_amount' => 1999]);
    actingIn($organization);

    $this->patchJson("/api/v1/prices/{$price->id}", ['unit_amount' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('unit_amount');
    $this->patchJson("/api/v1/prices/{$price->id}", ['currency' => 'USD'])
        ->assertUnprocessable()->assertJsonValidationErrors('currency');

    $this->patchJson("/api/v1/prices/{$price->id}", ['nickname' => 'Legacy', 'active' => false])
        ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.unit_amount', 1999);
});

it('does not attach prices to products of other organizations', function () {
    $organization = organization();
    $foreignProduct = product(organization());
    actingIn($organization);

    $this->postJson('/api/v1/prices', [
        'product_id' => $foreignProduct->id, 'currency' => 'EUR', 'unit_amount' => 100, 'billing_interval' => 'month',
    ])->assertUnprocessable()->assertJsonValidationErrors('product_id');

    $this->getJson("/api/v1/products/{$foreignProduct->id}")->assertNotFound();
});
