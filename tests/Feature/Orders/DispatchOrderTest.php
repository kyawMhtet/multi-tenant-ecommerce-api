<?php

use App\Models\DeliveryProvider;

/**
 * Recording which courier took an order out.
 *
 * The design under test: dispatch is its own axis, not an order status,
 * and the courier's name is snapshotted onto the order so history survives
 * the courier row being deleted.
 */
test('dispatching records the courier, tracking number and who handed it over', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
            'tracking_number' => 'RE123456789',
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_provider_id', $provider->id)
        ->assertJsonPath('data.delivery_provider_name', 'Royal Express')
        ->assertJsonPath('data.tracking_number', 'RE123456789')
        ->assertJsonPath('data.is_dispatched', true)
        ->assertJsonPath('data.dispatched_by_name', $user->name);

    expect($order->fresh()->dispatched_at)->not->toBeNull();
});

test('dispatching leaves the order status and payment status alone', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    expect($order->status)->toBe('pending')
        ->and($order->payment_status)->toBe('unpaid');

    $token = $user->createToken('t')->plainTextToken;

    // A cash-on-delivery parcel goes out while still unpaid. Nudging
    // status here would assert something about the money that isn't true.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.is_dispatched', true);
});

test('a tracking number is optional, for a shop using its own rider', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant, ['name' => 'Our own rider']);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_provider_name', 'Our own rider')
        ->assertJsonPath('data.tracking_number', null)
        ->assertJsonPath('data.is_dispatched', true);
});

test('a pickup order cannot be dispatched', function () {
    [$tenant, $user] = makeTenantUser();
    $tenant->update(['allows_pickup' => true]);
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['fulfillment_type' => 'pickup']);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
        ])
        ->assertStatus(422);

    expect($order->fresh()->dispatched_at)->toBeNull();
});

test('a cancelled order cannot be dispatched', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'customer_cancelled'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
        ])
        ->assertStatus(422);

    expect($order->fresh()->dispatched_at)->toBeNull();
});

test('re-dispatching overwrites the courier and the time it went out', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $first = createDeliveryProviderForTenant($tenant, ['name' => 'Royal Express']);
    $second = createDeliveryProviderForTenant($tenant, ['name' => 'Ninja Van']);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $first->id,
            'tracking_number' => 'RE1',
        ])
        ->assertOk();

    // The first courier lost it; the shop sends a replacement with
    // someone else. The useful "when did this go out" is the real one.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $second->id,
            'tracking_number' => 'NV9',
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_provider_name', 'Ninja Van')
        ->assertJsonPath('data.tracking_number', 'NV9');
});

test('deleting a courier keeps the name on orders it already carried', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", [
            'delivery_provider_id' => $provider->id,
            'tracking_number' => 'RE123',
        ])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/delivery-providers/{$provider->id}")
        ->assertNoContent();

    // The FK is nullOnDelete, but the snapshot is what answers "who
    // carried this parcel" — and it still does.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.delivery_provider_id', null)
        ->assertJsonPath('data.delivery_provider_name', 'Royal Express')
        ->assertJsonPath('data.tracking_number', 'RE123')
        ->assertJsonPath('data.is_dispatched', true);
});

test('couriers round-trip through their own endpoints', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $id = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/delivery-providers', [
            'name' => 'J&T Express',
            'phone' => '09777888999',
            'note' => 'Picks up daily at 4pm',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'J&T Express')
        ->json('data.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/delivery-providers/{$id}", ['phone' => '09000111222'])
        ->assertOk()
        ->assertJsonPath('data.phone', '09000111222')
        ->assertJsonPath('data.name', 'J&T Express');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/delivery-providers')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a shop cannot add the same courier twice, but two shops can share a name', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Uniqueness is per shop: tenant B already using the name must not
    // block tenant A from adding it.
    createDeliveryProviderForTenant($tenantB, ['name' => 'Royal Express']);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/delivery-providers', ['name' => 'Royal Express'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/delivery-providers', ['name' => 'Royal Express'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('tenant A cannot see, edit or dispatch with tenant B couriers', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $providerB = createDeliveryProviderForTenant($tenantB, ['name' => 'B Courier']);
    createDeliveryProviderForTenant($tenantA, ['name' => 'A Courier']);

    $variantA = createProductForTenant($tenantA)->variants->first();
    $orderA = createOnlineOrderForTenant($tenantA, [
        ['product_variant_slug' => $variantA->slug, 'quantity' => 1],
    ]);

    $tokenA = $userA->createToken('t')->plainTextToken;

    // Listing shows only A's own.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/delivery-providers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'A Courier');

    // B's courier is invisible to route-model binding, not merely
    // forbidden — the global scope makes it not exist for tenant A.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson("/api/v1/delivery-providers/{$providerB->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->deleteJson("/api/v1/delivery-providers/{$providerB->id}")
        ->assertNotFound();

    // And it can't be smuggled in through the dispatch body either.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/orders/{$orderA->id}/dispatch", [
            'delivery_provider_id' => $providerB->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('delivery_provider_id');

    expect($providerB->fresh()->name)->toBe('B Courier')
        ->and(DeliveryProvider::withoutGlobalScopes()->count())->toBe(2)
        ->and($orderA->fresh()->dispatched_at)->toBeNull();
});
