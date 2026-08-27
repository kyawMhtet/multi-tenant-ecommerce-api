<?php

/**
 * This endpoint aggregates across a tenant's whole order/product history
 * rather than fetching a single already-scoped row, so it's worth its own
 * isolation test rather than assuming the coverage on individual resources
 * (products, orders) already proves an aggregate can't leak — a COUNT() or
 * SUM() that forgot the tenant scope would fail silently (a wrong number,
 * not a 404), which is exactly the kind of bug per-resource tests wouldn't
 * catch.
 *
 * Fixture orders are created via the shared createPosOrderForTenant()
 * helper (tests/Pest.php), not authenticated HTTP calls — see that
 * helper's docblock for why. Only the one HTTP call this test actually
 * needs to prove (the dashboard GET as tenant A) goes through the real
 * request/auth stack; everything else is set up directly.
 */
test('the dashboard summary only reflects the current tenant, not another tenant with more data', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Tenant A: one active product, one low-stock variant, one order.
    $productA = createProductForTenant($tenantA, variantOverrides: [
        'current_stock' => 2, 'low_stock_threshold' => 5,
    ]);
    $variantA = $productA->variants->first();

    $orderA = createPosOrderForTenant($tenantA, $userA, [
        ['product_variant_id' => $variantA->id, 'quantity' => 1],
    ]);

    // Tenant B: deliberately MORE of everything — two products (both
    // low-stock) and two orders — so if isolation ever broke, tenant A's
    // summary would visibly gain extra counts, not coincidentally match.
    $productB1 = createProductForTenant($tenantB, ['name' => 'B Product 1'], [
        'sku' => 'B-SKU-1', 'current_stock' => 1, 'low_stock_threshold' => 10,
    ]);
    $productB2 = createProductForTenant($tenantB, ['name' => 'B Product 2'], [
        'sku' => 'B-SKU-2', 'current_stock' => 3, 'low_stock_threshold' => 10,
    ]);

    $orderBNumbers = collect([$productB1, $productB2])
        ->map(fn ($productB) => createPosOrderForTenant($tenantB, $userB, [
            ['product_variant_id' => $productB->variants->first()->id, 'quantity' => 1],
        ])->order_number)
        ->all();

    $tokenA = $userA->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/dashboard/summary');

    $response->assertOk()
        ->assertJsonPath('data.active_product_count', 1)
        ->assertJsonPath('data.low_stock_variant_count', 1)
        ->assertJsonPath('data.today_order_count', 1)
        ->assertJsonCount(1, 'data.recent_orders')
        ->assertJsonCount(1, 'data.low_stock_variants')
        ->assertJsonPath('data.low_stock_variants.0.product_name', $productA->name)
        ->assertJsonPath('data.recent_orders.0.id', $orderA->id)
        ->assertJsonPath('data.recent_orders.0.order_number', $orderA->order_number);

    $orderNumbers = collect($response->json('data.recent_orders'))->pluck('order_number');
    $lowStockProductNames = collect($response->json('data.low_stock_variants'))->pluck('product_name');

    expect($lowStockProductNames)->not->toContain('B Product 1')
        ->and($lowStockProductNames)->not->toContain('B Product 2')
        ->and($orderNumbers->intersect($orderBNumbers))->toBeEmpty();
});
