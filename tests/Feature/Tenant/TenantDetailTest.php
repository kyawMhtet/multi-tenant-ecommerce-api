<?php

test('the tenant endpoint returns the authenticated user own tenant', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: [
        'name' => 'My Shop', 'slug' => 'my-shop', 'currency' => 'MMK',
    ]);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.name', 'My Shop')
        ->assertJsonPath('data.slug', 'my-shop')
        ->assertJsonPath('data.currency', 'MMK');
});

/**
 * Two separate tests, not one test with two sequential authenticated
 * calls: Laravel's AuthManager caches guard instances per name, and
 * Sanctum's RequestGuard caches the resolved user after first access.
 * Pest reuses one container across multiple HTTP calls within a single
 * test, so a second authenticated call with a *different* user's token
 * in the same test would silently still resolve as the first user (a
 * real gotcha hit and worked around earlier in this app's test suite —
 * see tests/Feature/Dashboard/DashboardSummaryTest.php). Splitting
 * across two test() functions sidesteps it cleanly, since each gets a
 * fresh container.
 */
test('tenant A sees its own tenant, not tenant B', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'name' => 'Shop A', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'name' => 'Shop B', 'owner_email' => 'b@shop.test'],
    );

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.name', 'Shop A')
        ->assertJsonPath('data.id', $tenantA->id);
});

test('tenant B sees its own tenant, not tenant A', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'name' => 'Shop A', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'name' => 'Shop B', 'owner_email' => 'b@shop.test'],
    );

    $tokenB = $userB->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.name', 'Shop B')
        ->assertJsonPath('data.id', $tenantB->id);
});

test('the tenant endpoint requires authentication', function () {
    $this->getJson('/api/v1/tenant')->assertUnauthorized();
});
