<?php

/**
 * Delivery fee.
 *
 * Two things under test, and the second is the subtle one:
 *   1. The fee is resolved server-side from the shop's own configuration
 *      and added to the total — never read from the request body.
 *   2. It is EXCLUDED from margin reporting. A shop charging 2,000 to ship
 *      and paying the courier 2,000 has made nothing on delivery, so
 *      counting the fee as revenue while only goods appear in cost would
 *      overstate profit on every delivered order.
 */
function checkoutPayload(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Aye Aye',
        'customer_phone' => '09987654321',
        'fulfillment_type' => 'delivery',
        'delivery_address' => ['full_address' => 'No. 5, Yangon'],
        'payment_method' => 'cod',
    ], $overrides);
}

test('the shop delivery fee is added to the order total', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.subtotal', '10000.00')
        ->assertJsonPath('data.delivery_fee', '2000.00')
        ->assertJsonPath('data.total', '12000.00');
});

test('a pickup order is never charged a delivery fee', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000, 'allows_pickup' => true]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    // Structural, not configuration: there is no delivery to charge for.
    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'fulfillment_type' => 'pickup',
            'delivery_address' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.delivery_fee', '0.00')
        ->assertJsonPath('data.total', '5000.00');
});

test('a delivery fee sent in the request body is ignored', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    // A money amount a client can send is a money amount a client can set
    // to zero. The shop's own configuration is the only source.
    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'delivery_fee' => 0,
            'total' => 1,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.delivery_fee', '2000.00')
        ->assertJsonPath('data.total', '7000.00');
});

test('the fee is snapshotted, so raising it does not rewrite past orders', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $orderId = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
        ]))
        ->assertCreated()
        ->json('data.id');

    $tenant->update(['delivery_fee' => 9000]);

    $order = \App\Models\Order::withoutGlobalScopes()->findOrFail($orderId);

    expect((float) $order->delivery_fee)->toBe(2000.0)
        ->and((float) $order->total)->toBe(7000.0);
});

test('a POS counter sale carries no delivery fee', function () {
    [$tenant, $user] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    expect((float) $order->delivery_fee)->toBe(0.0)
        ->and((float) $order->total)->toBe(5000.0);
});

test('the storefront publishes the fee before the customer commits', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2500]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.delivery_fee', '2500.00');
});

test('the shop can change its delivery fee in settings', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['delivery_fee' => 3500])
        ->assertOk()
        ->assertJsonPath('data.delivery_fee', '3500.00');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['delivery_fee' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delivery_fee');
});

test('margin reporting excludes the delivery fee but still reports it', function () {
    [$tenant, $user] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    // Goods: 5000 sold, 3000 cost => 2000 real profit.
    $variant = createProductForTenant($tenant, variantOverrides: [
        'buying_price' => 3000, 'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $orderId = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
        ]))
        ->assertCreated()
        ->json('data.id');

    $token = $user->createToken('t')->plainTextToken;

    // Only paid/completed orders count as revenue.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/orders/{$orderId}", ['status' => 'completed'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/sales-profit')
        ->assertOk()
        // 7000 was banked, but only 5000 of it is a sale. Counting the
        // courier's 2000 as revenue would report 4000 profit on a 2000
        // margin — a 100% overstatement on this order.
        ->assertJsonPath('data.revenue', '5000.00')
        ->assertJsonPath('data.cost', '3000.00')
        ->assertJsonPath('data.profit', '2000.00')
        ->assertJsonPath('data.delivery_fees_collected', '2000.00')
        ->assertJsonPath('data.margin_percentage', 40);
});

test('the dashboard and the report agree on what a day sold', function () {
    [$tenant, $user] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'buying_price' => 3000, 'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $orderId = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', checkoutPayload([
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
        ]))
        ->assertCreated()
        ->json('data.id');

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/orders/{$orderId}", ['status' => 'completed'])
        ->assertOk();

    // Both read Order::GOODS_REVENUE_SQL, so they can't drift apart.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.today_sales_total', 5000)
        ->assertJsonPath('data.today_delivery_fees', 2000);
});

test('the amount handed to a payment gateway includes the delivery fee', function () {
    [$tenant] = makeTenantUser();
    $tenant->update(['delivery_fee' => 2000]);
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'selling_price' => 5000, 'current_stock' => 10,
    ])->variants->first();

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['fulfillment_type' => 'delivery', 'payment_method' => 'cod']);

    // CheckoutService and StripeGateway both charge $order->total, so the
    // fee reaches the gateway without either of them knowing it exists.
    expect((float) $order->total)->toBe(7000.0);
});
