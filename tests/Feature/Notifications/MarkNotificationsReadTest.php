<?php

test('marks a single notification as read', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);

    createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ]);

    $notification = $user->notifications->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNoContent();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('marks all notifications as read', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);

    createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ]);
    createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/notifications/read-all')
        ->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($user->notifications()->count())->toBe(2);
});

test('marking another tenant user\'s notification returns 404, not found', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productB = createProductForTenant($tenantB);
    createOnlineOrderForTenant($tenantB, [
        ['product_variant_slug' => $productB->variants->first()->slug, 'quantity' => 1],
    ]);

    $userBNotification = $userB->notifications->first();
    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson("/api/v1/notifications/{$userBNotification->id}/read")
        ->assertNotFound();

    expect($userBNotification->fresh()->read_at)->toBeNull();
});
