<?php

test('the public endpoint returns product and variant data by slug, with no auth or tenant header', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, ['name' => 'Cotton T-Shirt'], [
        'slug' => 'cotton-tshirt-l',
        'variant_name' => 'Large',
        'selling_price' => 5000,
        'current_stock' => 10,
    ]);

    $response = $this->getJson('/api/v1/public/products/cotton-tshirt-l');

    $response->assertOk()
        ->assertJsonPath('data.name', 'Cotton T-Shirt')
        ->assertJsonPath('data.variants.0.slug', 'cotton-tshirt-l')
        ->assertJsonPath('data.variants.0.variant_name', 'Large')
        ->assertJsonPath('data.variants.0.selling_price', '5000.00')
        ->assertJsonPath('data.variants.0.stock_status', 'in_stock');
});

test('the public endpoint never exposes cost fields, even if a bug elsewhere tried to pass them through', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Cotton T-Shirt'], [
        'slug' => 'cost-leak-check',
        'buying_price' => 999.99,
        'selling_price' => 5000,
        'current_stock' => 10,
    ]);

    $response = $this->getJson('/api/v1/public/products/cost-leak-check');

    $response->assertOk();

    $body = $response->json();

    expect(json_encode($body))->not->toContain('999.99')
        ->and($body['data'])->not->toHaveKey('buying_price')
        ->and($body['data']['variants'][0])->not->toHaveKey('buying_price')
        ->and($body['data']['variants'][0])->not->toHaveKey('unit_cost')
        ->and($body['data']['variants'][0])->not->toHaveKey('current_stock');
});

test('stock status reflects thresholds instead of exposing the raw count', function () {
    [$tenant] = makeTenantUser();

    createProductForTenant($tenant, ['name' => 'Out'], [
        'slug' => 'stock-out', 'current_stock' => 0,
    ]);
    createProductForTenant($tenant, ['name' => 'Low'], [
        'slug' => 'stock-low', 'current_stock' => 2, 'low_stock_threshold' => 5,
    ]);
    createProductForTenant($tenant, ['name' => 'Plenty'], [
        'slug' => 'stock-plenty', 'current_stock' => 50, 'low_stock_threshold' => 5,
    ]);
    createProductForTenant($tenant, ['name' => 'Untracked'], [
        'slug' => 'stock-untracked', 'track_stock' => false, 'current_stock' => 0,
    ]);

    $this->getJson('/api/v1/public/products/stock-out')->assertJsonPath('data.variants.0.stock_status', 'out_of_stock');
    $this->getJson('/api/v1/public/products/stock-low')->assertJsonPath('data.variants.0.stock_status', 'low_stock');
    $this->getJson('/api/v1/public/products/stock-plenty')->assertJsonPath('data.variants.0.stock_status', 'in_stock');
    $this->getJson('/api/v1/public/products/stock-untracked')->assertJsonPath('data.variants.0.stock_status', 'in_stock');
});

test('an inactive variant, product, or tenant is not found on the public endpoint', function () {
    [$tenantActive] = makeTenantUser();
    createProductForTenant($tenantActive, [], ['slug' => 'inactive-variant', 'is_active' => false]);
    createProductForTenant($tenantActive, ['is_active' => false], ['slug' => 'inactive-product']);

    [$tenantInactive] = makeTenantUser(
        userOverrides: ['email' => 'closed@shop.test'],
        tenantOverrides: ['slug' => 'closed-shop', 'owner_email' => 'closed@shop.test', 'is_active' => false],
    );
    createProductForTenant($tenantInactive, [], ['slug' => 'inactive-tenant']);

    $this->getJson('/api/v1/public/products/inactive-variant')->assertNotFound();
    $this->getJson('/api/v1/public/products/inactive-product')->assertNotFound();
    $this->getJson('/api/v1/public/products/inactive-tenant')->assertNotFound();
    $this->getJson('/api/v1/public/products/does-not-exist')->assertNotFound();
});

test('the slug is globally unique across tenants, not just per tenant', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    [$tenant2] = makeTenantUser(
        userOverrides: ['email' => 'other@shop.test'],
        tenantOverrides: ['slug' => 'other-shop', 'owner_email' => 'other@shop.test'],
    );
    createProductForTenant($tenant2, [], ['slug' => 'taken-slug']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/products', [
            'name' => 'Another Product',
            'variant' => [
                'sku' => 'ANOTHER-1',
                'buying_price' => 100,
                'selling_price' => 200,
            ],
        ]);

    $response->assertCreated();

    expect($response->json('data.variants.0.slug'))
        ->not->toBe('taken-slug');
});
