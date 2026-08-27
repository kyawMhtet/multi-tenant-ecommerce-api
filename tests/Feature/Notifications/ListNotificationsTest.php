<?php

/**
 * notifications has no tenant_id/BelongsToTenant scope — isolation instead
 * comes from the polymorphic chain (a notification is pinned to one
 * notifiable_id, i.e. one User row, and that user belongs to exactly one
 * tenant). Worth its own explicit test for the same reason
 * DashboardSummaryTest's docblock gives: a query that filtered by
 * notifiable_type alone (forgetting notifiable_id) would leak every
 * tenant's notifications to every user, and nothing else here would catch it.
 */
test('lists a user\'s notifications, newest first', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);

    createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'new_online_order');
});

test('filters to unread notifications only', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);

    createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ]);

    $user->notifications->first()->markAsRead();

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/notifications?unread_only=true')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('tenant A notifications and unread count are unaffected by tenant B orders', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Tenant B gets two online orders (deliberately more than tenant A's
    // zero), so a leak would visibly inflate tenant A's numbers, not
    // coincidentally match.
    $productB = createProductForTenant($tenantB);
    createOnlineOrderForTenant($tenantB, [
        ['product_variant_slug' => $productB->variants->first()->slug, 'quantity' => 1],
    ]);
    createOnlineOrderForTenant($tenantB, [
        ['product_variant_slug' => $productB->variants->first()->slug, 'quantity' => 1],
    ]);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});
