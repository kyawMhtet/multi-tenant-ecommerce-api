<?php

use App\Models\StockMovement;

test('restocking increments current_stock and logs a purchase movement', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10, 'buying_price' => 1000]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants/{$variant->id}/restock", [
            'quantity' => 20,
            'unit_cost' => 1200,
            'note' => 'Supplier ABC, invoice #123',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.current_stock', '30.00')
        ->assertJsonPath('data.buying_price', '1200.00');

    $movement = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'purchase')
        ->firstOrFail();

    expect((float) $movement->quantity)->toBe(20.0)
        ->and((float) $movement->unit_cost)->toBe(1200.0)
        ->and((float) $movement->balance_after)->toBe(30.0)
        ->and($movement->note)->toBe('Supplier ABC, invoice #123')
        ->and($movement->created_by)->toBe($user->id);
});

test('restocking without unit_cost leaves buying_price unchanged', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10, 'buying_price' => 1000]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants/{$variant->id}/restock", [
            'quantity' => 5,
        ])->assertOk();

    expect((float) $variant->fresh()->buying_price)->toBe(1000.0)
        ->and((float) $variant->fresh()->current_stock)->toBe(15.0);

    $movement = StockMovement::where('product_variant_id', $variant->id)->where('type', 'purchase')->firstOrFail();
    expect($movement->unit_cost)->toBeNull();
});

test('rejects a zero or negative restock quantity', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants/{$variant->id}/restock", [
            'quantity' => 0,
        ])->assertStatus(422);

    expect((float) $variant->fresh()->current_stock)->toBe(10.0);
});

test('rejects restocking a variant that does not track stock', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['track_stock' => false, 'current_stock' => 0]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants/{$variant->id}/restock", [
            'quantity' => 5,
        ])->assertStatus(422);

    expect((float) $variant->fresh()->current_stock)->toBe(0.0);
});

test('rejects restocking a variant through a product it does not belong to', function () {
    [$tenant, $user] = makeTenantUser();
    $productA = createProductForTenant($tenant, ['name' => 'Product A']);
    $productB = createProductForTenant($tenant, ['name' => 'Product B'], ['current_stock' => 10]);
    $variantOfB = $productB->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$productA->id}/variants/{$variantOfB->id}/restock", [
            'quantity' => 5,
        ])->assertNotFound();

    expect((float) $variantOfB->fresh()->current_stock)->toBe(10.0);
});

test('tenant A cannot restock tenant B variant', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB, variantOverrides: ['current_stock' => 10]);
    $variantB = $productB->variants->first();
    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/products/{$productB->id}/variants/{$variantB->id}/restock", [
            'quantity' => 5,
        ])->assertNotFound();

    expect((float) $variantB->fresh()->current_stock)->toBe(10.0);
});
