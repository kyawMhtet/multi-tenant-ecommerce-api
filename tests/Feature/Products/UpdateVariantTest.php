<?php

test('updates a variant\'s editable fields', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['sku' => 'TSHIRT-M', 'selling_price' => 5000]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'selling_price' => 6000,
            'low_stock_threshold' => 5,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.selling_price', '6000.00')
        ->assertJsonPath('data.sku', 'TSHIRT-M');

    expect((float) $variant->fresh()->low_stock_threshold)->toBe(5.0);
});

test('updating a variant does not touch current_stock even if sent', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'current_stock' => 999,
        ])->assertOk();

    expect((float) $variant->fresh()->current_stock)->toBe(10.0);
});

test('allows updating a variant while keeping its own unchanged sku', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['sku' => 'TSHIRT-M']);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'sku' => 'TSHIRT-M',
            'variant_name' => 'Medium',
        ])->assertOk()
        ->assertJsonPath('data.variant_name', 'Medium');
});

test('rejects a sku that collides with a different variant', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['sku' => 'TSHIRT-M']);
    $variant = $product->variants->first();

    $this->actingAs($user);
    app(App\Services\ProductService::class)->addVariant($product, [
        'sku' => 'TSHIRT-L', 'buying_price' => 3000, 'selling_price' => 5000,
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'sku' => 'TSHIRT-L',
        ])->assertStatus(422);
});

test('rejects updating a variant through a product it does not belong to', function () {
    [$tenant, $user] = makeTenantUser();
    $productA = createProductForTenant($tenant, ['name' => 'Product A']);
    $productB = createProductForTenant($tenant, ['name' => 'Product B']);
    $variantOfB = $productB->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$productA->id}/variants/{$variantOfB->id}", [
            'variant_name' => 'Hijacked',
        ])->assertNotFound();

    expect($variantOfB->fresh()->variant_name)->not->toBe('Hijacked');
});

test('tenant A cannot update tenant B variant', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB);
    $variantB = $productB->variants->first();
    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson("/api/v1/products/{$productB->id}/variants/{$variantB->id}", [
            'variant_name' => 'Hijacked',
        ])->assertNotFound();

    expect($variantB->fresh()->variant_name)->not->toBe('Hijacked');
});
