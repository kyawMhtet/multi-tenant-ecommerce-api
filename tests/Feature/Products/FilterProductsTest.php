<?php

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
});

test('filters products by search term matching product name', function () {
    createProductForTenant($this->tenant, ['name' => 'Blue Cotton Shirt']);
    createProductForTenant($this->tenant, ['name' => 'Leather Wallet']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=Cotton')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Blue Cotton Shirt');
});

test('filters products by search term matching variant sku', function () {
    createProductForTenant($this->tenant, ['name' => 'Blue Cotton Shirt'], ['sku' => 'SKU-MATCH-001']);
    createProductForTenant($this->tenant, ['name' => 'Leather Wallet'], ['sku' => 'SKU-OTHER-002']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=MATCH')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Blue Cotton Shirt');
});

test('filters products by search term matching variant barcode', function () {
    createProductForTenant($this->tenant, ['name' => 'Blue Cotton Shirt'], ['barcode' => '8801234567890']);
    createProductForTenant($this->tenant, ['name' => 'Leather Wallet'], ['barcode' => '8809876543210']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=8801234567890')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Blue Cotton Shirt');
});

test('filters products by category_id', function () {
    $categoryA = createCategoryForTenant($this->tenant, ['name' => 'Shirts', 'slug' => 'shirts']);
    $categoryB = createCategoryForTenant($this->tenant, ['name' => 'Wallets', 'slug' => 'wallets']);

    createProductForTenant($this->tenant, ['name' => 'Blue Shirt', 'category_id' => $categoryA->id]);
    createProductForTenant($this->tenant, ['name' => 'Brown Wallet', 'category_id' => $categoryB->id]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/products?category_id={$categoryA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Blue Shirt');
});

test('filters products by is_active true and false separately', function () {
    createProductForTenant($this->tenant, ['name' => 'Active Product', 'is_active' => true]);
    createProductForTenant($this->tenant, ['name' => 'Inactive Product', 'is_active' => false]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?is_active=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active Product');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?is_active=false')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Inactive Product');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('filters products by low_stock', function () {
    createProductForTenant($this->tenant, ['name' => 'Low Stock Item'], [
        'track_stock' => true, 'current_stock' => 2, 'low_stock_threshold' => 5,
    ]);
    createProductForTenant($this->tenant, ['name' => 'Well Stocked Item'], [
        'track_stock' => true, 'current_stock' => 50, 'low_stock_threshold' => 5,
    ]);
    createProductForTenant($this->tenant, ['name' => 'Untracked Item'], [
        'track_stock' => false, 'current_stock' => 0, 'low_stock_threshold' => 5,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?low_stock=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Low Stock Item');
});

test('combines search, category_id, and is_active filters together', function () {
    $category = createCategoryForTenant($this->tenant, ['name' => 'Shirts', 'slug' => 'shirts']);

    // Matches search + category, but not is_active — must be excluded when
    // all three are applied together, proving the filters AND rather than
    // the search clause's internal OR leaking out to the whole query.
    createProductForTenant($this->tenant, [
        'name' => 'Cotton Shirt', 'category_id' => $category->id, 'is_active' => false,
    ]);
    createProductForTenant($this->tenant, [
        'name' => 'Cotton Shirt Deluxe', 'category_id' => $category->id, 'is_active' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/products?search=Cotton&category_id={$category->id}&is_active=true")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cotton Shirt Deluxe');
});

test('rejects a category_id belonging to another tenant', function () {
    [$otherTenant] = makeTenantUser(
        userOverrides: ['email' => 'other@shop.test'],
        tenantOverrides: ['slug' => 'other-shop', 'owner_email' => 'other@shop.test'],
    );
    $otherCategory = createCategoryForTenant($otherTenant);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/products?category_id={$otherCategory->id}")
        ->assertStatus(422);
});

test('tenant A filters cannot surface tenant B products', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Noise that would match tenant A's filters below if scoping were
    // broken: same search term, and a variant that satisfies the exact
    // low-stock condition.
    createProductForTenant($tenantB, ['name' => 'Cotton Shirt'], [
        'sku' => 'SKU-COTTON-B', 'track_stock' => true, 'current_stock' => 1, 'low_stock_threshold' => 5,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=Cotton')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?low_stock=true')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('tenant A sku search cannot surface tenant B products by sku alone', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b2@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b2', 'owner_email' => 'b2@shop.test'],
    );

    // Name deliberately does NOT contain the search term — a match can
    // only come from whereHas('variants', sku LIKE ...), isolating that
    // sub-query's tenant scoping from the top-level name match already
    // covered by the previous test.
    createProductForTenant($tenantB, ['name' => 'Leather Wallet'], ['sku' => 'SKU-COTTON-B']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=Cotton')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('tenant A barcode search cannot surface tenant B products by barcode alone', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b3@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b3', 'owner_email' => 'b3@shop.test'],
    );

    createProductForTenant($tenantB, ['name' => 'Leather Wallet'], ['barcode' => '8801234567890']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products?search=8801234567890')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
