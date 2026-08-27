<?php

test('tenant A cannot list tenant B products', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    createProductForTenant($tenantB, ['name' => 'Tenant B Product']);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', 'tenant-a')
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('tenant A cannot fetch tenant B product by id', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB, ['name' => 'Tenant B Product']);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', 'tenant-a')
        ->getJson("/api/v1/products/{$productB->id}")
        ->assertNotFound();
});
