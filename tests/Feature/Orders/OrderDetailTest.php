<?php

test('the order detail endpoint returns the full order with item names and quantities', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, ['name' => 'Cotton T-Shirt'], [
        'variant_name' => 'Large', 'selling_price' => 6000, 'current_stock' => 10,
    ]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $orderId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/orders', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ])->assertCreated()
        ->json('data.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product_name', 'Cotton T-Shirt')
        ->assertJsonPath('data.items.0.variant_name', 'Large')
        ->assertJsonPath('data.items.0.quantity', '2.00');
});

/**
 * variant_name is null for most order items in practice (a simple
 * product's single variant has no name), so sku/attributes are usually the
 * only thing identifying which item was actually sold — and like
 * unit_price/unit_cost they're snapshotted, not looked up live, so
 * renaming or re-SKU'ing a variant can never rewrite what a historical
 * order appears to have contained.
 */
test('order items snapshot the variant sku and attributes at sale time', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, ['name' => 'Cotton T-Shirt'], [
        'sku' => 'TSHIRT-RED-L',
        'variant_name' => null,
        'attributes' => ['size' => 'L', 'color' => 'Red'],
        'selling_price' => 6000,
        'current_stock' => 10,
    ]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $orderId = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

    // Re-SKU and rename the variant after the sale — the order must still
    // report what was actually sold, not today's values.
    $variant->update(['sku' => 'CHANGED-SKU', 'attributes' => ['size' => 'XXL']]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.items.0.sku', 'TSHIRT-RED-L')
        ->assertJsonPath('data.items.0.attributes.size', 'L')
        ->assertJsonPath('data.items.0.attributes.color', 'Red')
        ->assertJsonPath('data.items.0.variant_name', null);
});

test('the order detail response never exposes cost fields', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: [
        'buying_price' => 111.11, 'selling_price' => 200, 'current_stock' => 10,
    ]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $orderId = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/orders', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated()
        ->json('data.id');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('111.11')
        ->and($response->json('data.items.0'))->not->toHaveKey('unit_cost');
});

/**
 * Tenant B's order is created directly via OrderService (createPosOrderForTenant),
 * not an authenticated HTTP call — Laravel's Sanctum guard caches the
 * resolved user for the rest of the test process, so a second
 * authenticated call with a different user's token would silently still
 * resolve as the first (see tests/Pest.php helper docblock). Only the
 * one HTTP call this test needs to prove — fetching order B's id as
 * tenant A — goes through the real request/auth stack.
 */
test('a tenant cannot fetch another tenant order by id', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB, variantOverrides: ['current_stock' => 10]);
    $variantB = $productB->variants->first();

    $orderB = createPosOrderForTenant($tenantB, $userB, [
        ['product_variant_id' => $variantB->id, 'quantity' => 1],
    ]);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', 'tenant-a')
        ->getJson("/api/v1/orders/{$orderB->id}")
        ->assertNotFound();
});
