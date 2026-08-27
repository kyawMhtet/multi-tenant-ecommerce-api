<?php

test('lists active products for the tenant, header-scoped, no auth', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Cotton Shirt']);
    createProductForTenant($tenant, ['name' => 'Leather Wallet']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('excludes inactive products', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Visible', 'is_active' => true]);
    createProductForTenant($tenant, ['name' => 'Hidden', 'is_active' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible');
});

test('excludes a product whose only variant is inactive', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'All Variants Off'], ['is_active' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('filters by search term matching product name', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Blue Cotton Shirt']);
    createProductForTenant($tenant, ['name' => 'Leather Wallet']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products?search=Cotton')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Blue Cotton Shirt');
});

test('search does not match variant sku, unlike the admin filter', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Blue Shirt'], ['sku' => 'MATCH-001']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products?search=MATCH')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('filters by category_id', function () {
    [$tenant] = makeTenantUser();
    $category = createCategoryForTenant($tenant, ['name' => 'Shirts']);
    createProductForTenant($tenant, ['name' => 'Shirt', 'category_id' => $category->id]);
    createProductForTenant($tenant, ['name' => 'Wallet']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson("/api/v1/public/products?category_id={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Shirt');
});

test('rejects a category_id belonging to another tenant', function () {
    [$tenant] = makeTenantUser();
    [$otherTenant] = makeTenantUser(
        userOverrides: ['email' => 'other@shop.test'],
        tenantOverrides: ['slug' => 'other-shop', 'owner_email' => 'other@shop.test'],
    );
    $otherCategory = createCategoryForTenant($otherTenant);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson("/api/v1/public/products?category_id={$otherCategory->id}")
        ->assertStatus(422);
});

test('requires a tenant slug header', function () {
    makeTenantUser();

    $this->getJson('/api/v1/public/products')->assertNotFound();
});

test('never exposes cost fields or the shop payload per item', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Shirt'], ['buying_price' => 111.11]);

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('111.11')
        ->and($response->json('data.0'))->not->toHaveKey('buying_price')
        // The shop embed exists on the single-product endpoint (which has
        // no other way to learn the shop's identity); here the caller
        // already knows the tenant from the header it just sent, so
        // repeating full shop data on every row would be pure waste.
        ->and($response->json('data.0'))->not->toHaveKey('shop');
});

test('tenant A listing never surfaces tenant B products', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    createProductForTenant($tenantB, ['name' => 'Tenant B Product']);

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('public categories are listed header-scoped, no auth', function () {
    [$tenant] = makeTenantUser();
    createCategoryForTenant($tenant, ['name' => 'Wallets']);
    createCategoryForTenant($tenant, ['name' => 'Shirts']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Shirts');
});

test('tenant A public categories never surface tenant B categories', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a2@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a2', 'owner_email' => 'a2@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b2@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b2', 'owner_email' => 'b2@shop.test'],
    );
    createCategoryForTenant($tenantB, ['name' => 'Tenant B Category']);

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/public/categories')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
