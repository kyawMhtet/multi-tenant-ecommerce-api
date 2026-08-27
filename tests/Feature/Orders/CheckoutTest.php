<?php

use App\Models\Order;
use App\Models\StockMovement;

test('a successful checkout decrements stock and logs a matching stock movement', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/orders', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 3],
            ],
            'payment_method' => 'cash',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonCount(1, 'data.items');

    expect($variant->fresh()->current_stock)->toEqual(7.0);

    $movement = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'sale')
        ->firstOrFail();

    expect((float) $movement->quantity)->toBe(-3.0)
        ->and((float) $movement->balance_after)->toBe(7.0)
        ->and($movement->reference_type)->toBe(Order::class);
});

test('checking out with insufficient stock throws and creates no order', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 2]);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/orders', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 5],
            ],
            'payment_method' => 'cash',
        ]);

    $response->assertUnprocessable();

    expect(Order::count())->toBe(0)
        ->and($variant->fresh()->current_stock)->toEqual(2.0)
        ->and(StockMovement::count())->toBe(0);
});

test('a partially valid checkout is fully rolled back, not partially applied', function () {
    [$tenant, $user] = makeTenantUser();
    $productA = createProductForTenant($tenant, ['name' => 'A'], ['sku' => 'SKU-A', 'current_stock' => 10]);
    $productB = createProductForTenant($tenant, ['name' => 'B'], ['sku' => 'SKU-B', 'current_stock' => 1]);
    $variantA = $productA->variants->first();
    $variantB = $productB->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/orders', [
            'items' => [
                ['product_variant_id' => $variantA->id, 'quantity' => 2],
                ['product_variant_id' => $variantB->id, 'quantity' => 5],
            ],
            'payment_method' => 'cash',
        ]);

    $response->assertUnprocessable();

    expect(Order::count())->toBe(0)
        ->and($variantA->fresh()->current_stock)->toEqual(10.0)
        ->and($variantB->fresh()->current_stock)->toEqual(1.0)
        ->and(StockMovement::count())->toBe(0);
});

test('a cashier cannot check out with another tenant product_variant_id', function () {
    [$tenantA, $userA] = makeTenantUser(
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

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/orders', [
            'items' => [
                ['product_variant_id' => $variantB->id, 'quantity' => 1],
            ],
            'payment_method' => 'cash',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.product_variant_id');

    expect(Order::count())->toBe(0)
        ->and($variantB->fresh()->current_stock)->toEqual(10.0);
});
