<?php

use App\Models\Product;
use App\Services\Billing\PlanCatalog;

/**
 * Proof that the gates actually fire. Every test here authenticates as a real
 * shop and goes through HTTP, because the thing worth pinning down is what a
 * client receives, not what a service returns.
 *
 * makeTenantUser() leaves a shop mid-trial on the TOP plan, which is the
 * permissive case — so each of these calls subscribeTenant() to put it
 * somewhere that should be refused. A gate that never fires in tests is a
 * gate nobody knows is broken.
 */
function actingAsShop(array $subscription = [], array $tenantOverrides = []): array
{
    [$tenant, $user] = makeTenantUser(tenantOverrides: $tenantOverrides);
    subscribeTenant($tenant, $subscription);

    return [$tenant, $user->createToken('t')->plainTextToken];
}

function shopRequest(string $token): Illuminate\Testing\TestResponse|Illuminate\Foundation\Testing\TestCase
{
    return test()->withHeader('Authorization', "Bearer {$token}");
}

// ---------------------------------------------------------------------------
// Feature gates
// ---------------------------------------------------------------------------

test('a starter shop cannot read the profit report', function () {
    [, $token] = actingAsShop(['plan' => 'starter']);

    shopRequest($token)->getJson('/api/v1/reports/sales-profit')
        ->assertStatus(402)
        ->assertJsonPath('reason', 'feature_not_on_plan')
        ->assertJsonPath('feature', 'profit_reports')
        ->assertJsonPath('current_plan', 'starter');
});

test('a pro shop can read the profit report', function () {
    [, $token] = actingAsShop(['plan' => 'pro']);

    shopRequest($token)->getJson('/api/v1/reports/sales-profit')->assertOk();
});

/**
 * 402, not 403. The shop IS allowed to do this — it simply has not paid for
 * it, and the admin app has to be able to tell those apart without reading
 * the message text.
 */
test('a starter shop cannot enable card payments or reach stripe onboarding', function () {
    [, $token] = actingAsShop(['plan' => 'starter']);

    shopRequest($token)->postJson('/api/v1/payments/methods', [
        'method' => 'card',
        'is_enabled' => true,
    ])->assertStatus(402)->assertJsonPath('feature', 'card_payments');

    shopRequest($token)->getJson('/api/v1/payments/stripe/status')->assertStatus(402);
    shopRequest($token)->postJson('/api/v1/payments/stripe/onboarding-link')->assertStatus(402);
});

/**
 * The manual methods carry the volume in this market. A plan that could not
 * take cash on delivery would not be a plan anyone could sell a shop.
 */
test('manual payment methods are never gated', function () {
    [, $token] = actingAsShop(['plan' => 'starter']);

    foreach (['cod', 'qr_transfer'] as $method) {
        shopRequest($token)->postJson('/api/v1/payments/methods', [
            'method' => $method,
            'is_enabled' => true,
        ])->assertSuccessful();
    }
});

/**
 * Turning a paid feature OFF must never require the plan that turned it on,
 * or a downgraded shop is stuck with a method it can no longer use.
 */
test('a starter shop may still disable card payments', function () {
    [$tenant, $token] = actingAsShop(['plan' => 'pro']);

    shopRequest($token)->postJson('/api/v1/payments/methods', [
        'method' => 'card', 'is_enabled' => true,
    ])->assertSuccessful();

    subscribeTenant($tenant, ['plan' => 'starter']);

    shopRequest($token)->postJson('/api/v1/payments/methods', [
        'method' => 'card', 'is_enabled' => false,
    ])->assertSuccessful();
});

/**
 * allow_preorder is one field on a request that does plenty else, so it is
 * gated in the service rather than at the route — a shop editing a product
 * shouldn't have the whole edit rejected with a billing error.
 */
test('a starter shop cannot sell below zero stock', function () {
    [, $token] = actingAsShop(['plan' => 'starter']);

    $payload = [
        'name' => 'Imported Phone',
        'variant' => ['sku' => 'PH-1', 'selling_price' => 500000, 'buying_price' => 400000],
    ];

    shopRequest($token)->postJson('/api/v1/products', $payload)->assertCreated();

    shopRequest($token)->postJson('/api/v1/products', [
        ...$payload,
        'variant' => [...$payload['variant'], 'sku' => 'PH-2', 'allow_preorder' => true],
    ])->assertStatus(402)->assertJsonPath('feature', 'preorder');
});

// ---------------------------------------------------------------------------
// Limits
// ---------------------------------------------------------------------------

test('a starter shop is refused the product that would exceed its limit', function () {
    [$tenant, $token] = actingAsShop(['plan' => 'starter']);
    $limit = PlanCatalog::limitFor('starter', 'products');

    app()->instance('tenant', $tenant);
    for ($i = 0; $i < $limit; $i++) {
        Product::create(['name' => "Product {$i}"]);
    }
    app()->forgetInstance('tenant');

    shopRequest($token)->postJson('/api/v1/products', [
        'name' => 'One too many',
        'variant' => ['sku' => 'X-1', 'selling_price' => 1000, 'buying_price' => 500],
    ])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'plan_limit_exceeded')
        ->assertJsonPath('limit', 'products')
        ->assertJsonPath('maximum', $limit)
        ->assertJsonPath('current', $limit);

    // Refused BEFORE creating, so the count is unchanged rather than off by one.
    app()->instance('tenant', $tenant);
    expect(Product::count())->toBe($limit);
    app()->forgetInstance('tenant');
});

/**
 * The limit bites on CREATE only. Being over it is a coherent state, not
 * corruption — the same position taken on a variant sitting at -7 stock.
 */
test('a shop over its limit keeps everything it already has', function () {
    [$tenant, $token] = actingAsShop(['plan' => 'pro']);

    app()->instance('tenant', $tenant);
    foreach (range(1, 60) as $i) {
        Product::create(['name' => "Product {$i}"]);
    }
    app()->forgetInstance('tenant');

    subscribeTenant($tenant, ['plan' => 'starter']);

    shopRequest($token)->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('meta.total', 60);
});

test('a pro shop has no product ceiling', function () {
    expect(PlanCatalog::limitFor('pro', 'products'))->toBeNull();
});

// ---------------------------------------------------------------------------
// The lapsed shop
// ---------------------------------------------------------------------------

test('a lapsed shop cannot change its catalogue', function () {
    [, $token] = actingAsShop([
        'plan' => 'pro',
        'status' => 'past_due',
        'current_period_ends_at' => now()->subYear(),
    ]);

    shopRequest($token)->postJson('/api/v1/products', [
        'name' => 'New Product',
        'variant' => ['sku' => 'N-1', 'selling_price' => 1000, 'buying_price' => 500],
    ])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'subscription_inactive');

    shopRequest($token)->postJson('/api/v1/delivery-providers', [
        'name' => 'Ninja Van',
    ])->assertStatus(402);
});

/**
 * The exclusions are the design. A lapsed shop keeps running the business it
 * already has — it just cannot grow it.
 */
test('a lapsed shop can still read, sell and fulfil', function () {
    [$tenant, $token] = actingAsShop([
        'plan' => 'pro',
        'status' => 'past_due',
        'current_period_ends_at' => now()->subYear(),
    ]);

    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variantId = $product->variants->first()->id;

    // Reading the catalogue: its own data, never withheld.
    shopRequest($token)->getJson('/api/v1/products')->assertOk();
    shopRequest($token)->getJson('/api/v1/dashboard/summary')->assertOk();

    // Selling at the counter: stopping this stops the shop earning, and a
    // shop that cannot trade cannot pay.
    $order = shopRequest($token)->postJson('/api/v1/orders', [
        'items' => [['product_variant_id' => $variantId, 'quantity' => 1]],
        'payment_method' => 'cash',
    ])->assertCreated()->json('data.id');

    // Fulfilling it: blocking dispatch would strand a parcel a customer has
    // already paid for — the platform hurting a third party to collect from
    // someone else.
    $provider = createDeliveryProviderForTenant($tenant);

    shopRequest($token)->postJson("/api/v1/orders/{$order}/dispatch", [
        'delivery_provider_id' => $provider->id,
    ])->assertOk();
});

/**
 * The storefront belongs to the shop's customers, who are holding links to it
 * and did nothing wrong. It stays up.
 */
test('a lapsed shop keeps its public storefront', function () {
    [$tenant] = actingAsShop([
        'plan' => 'pro',
        'status' => 'past_due',
        'current_period_ends_at' => now()->subYear(),
    ]);

    createProductForTenant($tenant, variantOverrides: ['current_stock' => 5]);

    test()->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * Grace is the difference between "your card expired on Tuesday" and "you
 * have not paid in a month". A shop one day past its period must notice
 * nothing.
 */
test('a shop inside its grace period is unaffected', function () {
    [, $token] = actingAsShop([
        'plan' => 'pro',
        'status' => 'past_due',
        'current_period_ends_at' => now()->subDay(),
    ]);

    shopRequest($token)->postJson('/api/v1/products', [
        'name' => 'Still Fine',
        'variant' => ['sku' => 'G-1', 'selling_price' => 1000, 'buying_price' => 500],
    ])->assertCreated();
});
