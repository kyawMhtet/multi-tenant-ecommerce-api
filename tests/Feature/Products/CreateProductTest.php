<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductService;

test('creating a product always results in at least one variant', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/products', [
            'name' => 'Instant Coffee',
            'variant' => [
                'sku' => 'COFFEE-001',
                'buying_price' => 1000,
                'selling_price' => 1500,
            ],
        ]);

    $response->assertCreated()
        ->assertJsonCount(1, 'data.variants')
        ->assertJsonPath('data.variants.0.sku', 'COFFEE-001');

    expect(Product::first()->variants()->count())->toBe(1);
});

test('creating a product with starting stock records an initial stock movement', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/products', [
            'name' => 'Instant Coffee',
            'variant' => [
                'sku' => 'COFFEE-002',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'current_stock' => 20,
            ],
        ])->assertCreated();

    $variant = ProductVariant::where('sku', 'COFFEE-002')->firstOrFail();
    $movement = $variant->stockMovements()->firstOrFail();

    expect($variant->stockMovements()->count())->toBe(1)
        ->and($movement->type)->toBe('initial')
        ->and((float) $movement->balance_after)->toBe(20.0);
});

test('a product with no starting stock records no stock movement', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/products', [
            'name' => 'Made To Order Cake',
            'variant' => [
                'sku' => 'CAKE-001',
                'buying_price' => 2000,
                'selling_price' => 5000,
            ],
        ])->assertCreated();

    $variant = ProductVariant::where('sku', 'CAKE-001')->firstOrFail();

    expect($variant->stockMovements()->count())->toBe(0);
});

test('addVariant adds a further variant to an existing product', function () {
    [, $user] = makeTenantUser();
    $this->actingAs($user);

    $product = app(ProductService::class)->createProduct([
        'name' => 'T-Shirt',
        'variant' => ['sku' => 'TSHIRT-S', 'buying_price' => 3000, 'selling_price' => 5000],
    ]);

    app(ProductService::class)->addVariant($product, [
        'sku' => 'TSHIRT-M',
        'buying_price' => 3000,
        'selling_price' => 5000,
    ]);

    expect($product->variants()->count())->toBe(2);
});
