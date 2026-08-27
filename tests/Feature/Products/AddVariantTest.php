<?php

use App\Models\ProductVariant;

test('adds a variant to an existing product', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'TSHIRT-L',
            'buying_price' => 3000,
            'selling_price' => 5000,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.sku', 'TSHIRT-L');

    expect($product->variants()->count())->toBe(2);
});

test('adding a variant with starting stock records an initial stock movement', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'TSHIRT-XL',
            'buying_price' => 3000,
            'selling_price' => 5000,
            'current_stock' => 15,
        ])->assertCreated();

    $variant = ProductVariant::where('sku', 'TSHIRT-XL')->firstOrFail();
    $movement = $variant->stockMovements()->firstOrFail();

    expect($movement->type)->toBe('initial')
        ->and((float) $movement->balance_after)->toBe(15.0);
});

test('rejects a duplicate sku within the same tenant', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['sku' => 'TSHIRT-S']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'TSHIRT-S',
            'buying_price' => 3000,
            'selling_price' => 5000,
        ])->assertStatus(422);
});

test('tenant A cannot add a variant to tenant B product', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB);
    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/products/{$productB->id}/variants", [
            'sku' => 'HIJACK-001',
            'buying_price' => 100,
            'selling_price' => 150,
        ])->assertNotFound();

    expect(ProductVariant::where('sku', 'HIJACK-001')->exists())->toBeFalse();
});
