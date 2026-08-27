<?php

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
});

test('lists orders for the current tenant with customer and cashier info', function () {
    createPosOrderForTenant($this->tenant, $this->user, [
        ['product_variant_id' => createProductForTenant($this->tenant)->variants->first()->id, 'quantity' => 1],
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'pos')
        ->assertJsonPath('data.0.cashier_name', $this->user->name);
});

test('filters orders by status', function () {
    $variant = createProductForTenant($this->tenant)->variants->first();
    createPosOrderForTenant($this->tenant, $this->user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    app()->instance('tenant', $this->tenant);
    \App\Models\Order::first()->update(['status' => 'completed']);
    app()->forgetInstance('tenant');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders?status=completed')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders?status=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('filters orders by source', function () {
    $variant = createProductForTenant($this->tenant)->variants->first();
    createPosOrderForTenant($this->tenant, $this->user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders?source=pos')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders?source=online')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('filters orders by date range', function () {
    $variant = createProductForTenant($this->tenant)->variants->first();
    createPosOrderForTenant($this->tenant, $this->user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/orders?date_from={$today}&date_to={$tomorrow}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/orders?date_from={$yesterday}&date_to={$yesterday}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('rejects date_to before date_from', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders?date_from=2026-08-20&date_to=2026-08-10')
        ->assertStatus(422);
});

test('tenant A cannot list tenant B orders', function () {
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $variantB = createProductForTenant($tenantB)->variants->first();
    createPosOrderForTenant($tenantB, $userB, [
        ['product_variant_id' => $variantB->id, 'quantity' => 1],
    ]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
