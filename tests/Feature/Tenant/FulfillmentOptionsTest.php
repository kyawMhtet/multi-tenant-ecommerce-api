<?php

use App\Models\Order;
use App\Models\Tenant;

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
    enablePaymentMethodForTenant($this->tenant);
    $this->variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 20])->variants->first();
});

/**
 * Sets the flags directly rather than through a first authenticated PATCH.
 * Sanctum's guard caches the resolved user for the whole test process, so a
 * second request in the same test would read a STALE $request->user()->tenant
 * and still see the old values — see the createPosOrderForTenant() docblock
 * in tests/Pest.php. Each test therefore does its setup in the DB and keeps
 * exactly one real HTTP call for the behaviour under test.
 */
function setFulfillment(array $flags): void
{
    Tenant::whereKey(test()->tenant->id)->update($flags);
    test()->tenant->refresh();
}

function saveFulfillment(array $payload)
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->patchJson('/api/v1/tenant', $payload);
}

function order(string $type)
{
    return test()->withHeader('X-Tenant-Slug', test()->tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => test()->variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'payment_method' => 'cod',
            'fulfillment_type' => $type,
            'delivery_address' => $type === 'delivery' ? ['full_address' => 'No. 5, Yangon'] : null,
        ]);
}

test('a shop offers both delivery and pickup by default', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.allows_delivery', true)
        ->assertJsonPath('data.allows_pickup', true);
});

test('a shop can turn pickup off', function () {
    saveFulfillment(['allows_pickup' => false])
        ->assertOk()
        ->assertJsonPath('data.allows_pickup', false)
        ->assertJsonPath('data.allows_delivery', true);

    expect($this->tenant->fresh()->allows_pickup)->toBeFalse();
});

test('a delivery-only shop rejects a pickup order', function () {
    setFulfillment(['allows_pickup' => false]);

    order('pickup')
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment_type');

    expect(Order::count())->toBe(0);
});

test('a pickup-only shop rejects a delivery order', function () {
    setFulfillment(['allows_delivery' => false]);

    order('delivery')
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment_type');
});

test('a pickup-only shop still accepts a pickup order', function () {
    setFulfillment(['allows_delivery' => false]);

    order('pickup')->assertCreated();
});

/**
 * A shop with neither option can't take a storefront order at all — the
 * checkout would have nothing valid to submit.
 */
test('a shop cannot turn both options off at once', function () {
    saveFulfillment(['allows_delivery' => false, 'allows_pickup' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allows_delivery');

    expect($this->tenant->fresh()->allows_delivery)->toBeTrue();
});

/**
 * The guard has to consider the SAVED value for whichever flag the request
 * didn't mention — this is a partial update, so turning delivery off in a
 * request that never names pickup must still be caught when pickup is
 * already off.
 */
test('turning off the last remaining option is caught against the saved value', function () {
    setFulfillment(['allows_pickup' => false]);

    saveFulfillment(['allows_delivery' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allows_delivery');

    expect($this->tenant->fresh()->allows_delivery)->toBeTrue();
});

test('the storefront is told which options the shop offers', function () {
    setFulfillment(['allows_pickup' => false]);

    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.allows_delivery', true)
        ->assertJsonPath('data.allows_pickup', false);
});

test('one shop fulfillment settings never affect another', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    setFulfillment(['allows_pickup' => false]);

    expect(Tenant::findOrFail($tenantB->id)->allows_pickup)->toBeTrue();

    $this->withHeader('X-Tenant-Slug', $tenantB->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.allows_pickup', true);
});
