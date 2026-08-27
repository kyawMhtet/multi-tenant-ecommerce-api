<?php

/**
 * This endpoint aggregates across a tenant's whole order/order-item history
 * for a date range, the same "COUNT()/SUM() that forgot the tenant scope
 * fails silently, not with a 404" risk DashboardSummaryTest.php's docblock
 * describes — so it gets the same isolation-focused test treatment, plus a
 * test with an exactly known expected revenue/cost/profit, not just a
 * greater-than-zero assertion.
 */
test('computes exact revenue, cost, profit, margin, and daily breakdown for known orders', function () {
    [$tenant, $user] = makeTenantUser();
    $today = now()->toDateString();

    $productA = createProductForTenant($tenant, ['name' => 'Product A'], [
        'sku' => 'SKU-A', 'buying_price' => 60, 'selling_price' => 100,
    ]);
    createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $productA->variants->first()->id, 'quantity' => 3],
    ]);

    $productB = createProductForTenant($tenant, ['name' => 'Product B'], [
        'sku' => 'SKU-B', 'buying_price' => 20, 'selling_price' => 50,
    ]);
    createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $productB->variants->first()->id, 'quantity' => 2],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/reports/sales-profit?date_from={$today}&date_to={$today}");

    $response->assertOk()
        ->assertJsonPath('data.date_from', $today)
        ->assertJsonPath('data.date_to', $today)
        ->assertJsonPath('data.revenue', '400.00')
        ->assertJsonPath('data.cost', '220.00')
        ->assertJsonPath('data.profit', '180.00')
        ->assertJsonPath('data.margin_percentage', 45)
        ->assertJsonPath('data.order_count', 2)
        ->assertJsonPath('data.average_order_value', '200.00')
        ->assertJsonCount(1, 'data.daily')
        ->assertJsonPath('data.daily.0.date', $today)
        ->assertJsonPath('data.daily.0.revenue', '400.00')
        ->assertJsonPath('data.daily.0.cost', '220.00')
        ->assertJsonPath('data.daily.0.profit', '180.00')
        ->assertJsonPath('data.daily.0.order_count', 2);
});

test('tenant A report is unaffected by tenant B having larger, different orders', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    $today = now()->toDateString();

    $productA = createProductForTenant($tenantA, variantOverrides: ['buying_price' => 60, 'selling_price' => 100]);
    createPosOrderForTenant($tenantA, $userA, [
        ['product_variant_id' => $productA->variants->first()->id, 'quantity' => 1],
    ]);

    // Tenant B: deliberately larger and at different prices, so a leak
    // would visibly inflate/skew tenant A's numbers, not coincidentally match.
    $productB = createProductForTenant($tenantB, variantOverrides: ['buying_price' => 500, 'selling_price' => 1000]);
    createPosOrderForTenant($tenantB, $userB, [
        ['product_variant_id' => $productB->variants->first()->id, 'quantity' => 10],
    ]);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson("/api/v1/reports/sales-profit?date_from={$today}&date_to={$today}")
        ->assertOk()
        ->assertJsonPath('data.revenue', '100.00')
        ->assertJsonPath('data.cost', '60.00')
        ->assertJsonPath('data.profit', '40.00')
        ->assertJsonPath('data.order_count', 1)
        ->assertJsonPath('data.daily.0.revenue', '100.00')
        ->assertJsonPath('data.daily.0.cost', '60.00');
});

test('excludes orders whose status is not revenue-eligible', function () {
    [$tenant, $user] = makeTenantUser();
    $today = now()->toDateString();

    $product = createProductForTenant($tenant, variantOverrides: ['buying_price' => 60, 'selling_price' => 100]);
    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    app()->instance('tenant', $tenant);
    $order->update(['status' => 'pending']);
    app()->forgetInstance('tenant');

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/reports/sales-profit?date_from={$today}&date_to={$today}")
        ->assertOk()
        ->assertJsonPath('data.revenue', '0.00')
        ->assertJsonPath('data.cost', '0.00')
        ->assertJsonPath('data.order_count', 0);
});

test('excludes orders outside the requested date range', function () {
    [$tenant, $user] = makeTenantUser();
    $yesterday = now()->subDay()->toDateString();

    $product = createProductForTenant($tenant, variantOverrides: ['buying_price' => 60, 'selling_price' => 100]);
    createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/reports/sales-profit?date_from={$yesterday}&date_to={$yesterday}")
        ->assertOk()
        ->assertJsonPath('data.revenue', '0.00')
        ->assertJsonPath('data.order_count', 0);
});

test('defaults to the current month through today when no date range is given', function () {
    [, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/sales-profit')
        ->assertOk()
        ->assertJsonPath('data.date_from', now()->startOfMonth()->toDateString())
        ->assertJsonPath('data.date_to', now()->toDateString());
});

test('a range with no orders returns zeros and null derived fields, not division errors', function () {
    [, $user] = makeTenantUser();
    $today = now()->toDateString();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/reports/sales-profit?date_from={$today}&date_to={$today}")
        ->assertOk()
        ->assertJsonPath('data.revenue', '0.00')
        ->assertJsonPath('data.cost', '0.00')
        ->assertJsonPath('data.profit', '0.00')
        ->assertJsonPath('data.order_count', 0)
        ->assertJsonPath('data.margin_percentage', null)
        ->assertJsonPath('data.average_order_value', null)
        ->assertJsonPath('data.daily.0.revenue', '0.00')
        ->assertJsonPath('data.daily.0.order_count', 0);
});

test('rejects date_to before date_from', function () {
    [, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/sales-profit?date_from=2026-08-20&date_to=2026-08-10')
        ->assertStatus(422);
});
