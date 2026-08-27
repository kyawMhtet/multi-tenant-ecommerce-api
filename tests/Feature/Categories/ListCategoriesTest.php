<?php

test('lists categories for the current tenant, ordered by name', function () {
    [$tenant, $user] = makeTenantUser();
    createCategoryForTenant($tenant, ['name' => 'Wallets', 'slug' => 'wallets']);
    createCategoryForTenant($tenant, ['name' => 'Shirts', 'slug' => 'shirts']);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Shirts')
        ->assertJsonPath('data.1.name', 'Wallets');
});

test('tenant A cannot list tenant B categories', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    createCategoryForTenant($tenantB, ['name' => 'Tenant B Category']);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
