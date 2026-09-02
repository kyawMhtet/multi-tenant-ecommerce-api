<?php

use App\Models\StockMovement;

test('updates order status and payment_status', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/orders/{$order->id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment_status', 'paid');

    // Refunding is its own action now — it records who and when, which a
    // generic status edit can't, so PATCH deliberately no longer accepts it.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/orders/{$order->id}", ['payment_status' => 'refunded'])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/refund")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment_status', 'refunded');
});

test('cancelling an order restores stock via a return_in movement', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 3],
    ]);

    expect($variant->fresh()->current_stock)->toEqual(7.0);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'out_of_stock'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($variant->fresh()->current_stock)->toEqual(10.0);

    $movement = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'return_in')
        ->firstOrFail();

    expect((float) $movement->quantity)->toBe(3.0)
        ->and((float) $movement->balance_after)->toBe(10.0)
        ->and($movement->reference_type)->toBe(\App\Models\Order::class)
        ->and($movement->reference_id)->toBe($order->id);
});

test('cancelling an order does not restore stock for untracked variants', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['track_stock' => false, 'current_stock' => 0]);
    $variant = $product->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 2],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'out_of_stock'])
        ->assertOk();

    expect((float) $variant->fresh()->current_stock)->toBe(0.0)
        ->and(StockMovement::where('product_variant_id', $variant->id)->where('type', 'return_in')->exists())->toBeFalse();
});

test('cancelling an already-cancelled order does not double-restore stock', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 3],
    ]);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'out_of_stock'])
        ->assertOk();

    expect($variant->fresh()->current_stock)->toEqual(10.0);

    // Same order, cancelled again — must not credit stock a second time.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'out_of_stock'])
        ->assertOk();

    expect($variant->fresh()->current_stock)->toEqual(10.0)
        ->and(StockMovement::where('product_variant_id', $variant->id)->where('type', 'return_in')->count())->toBe(1);
});

test('rejects an invalid status value', function () {
    [$tenant, $user] = makeTenantUser();
    $variant = createProductForTenant($tenant)->variants->first();
    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/orders/{$order->id}", ['status' => 'not-a-real-status'])
        ->assertStatus(422);
});

test('tenant A cannot update tenant B order', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $variantB = createProductForTenant($tenantB)->variants->first();
    $orderB = createPosOrderForTenant($tenantB, $userB, [
        ['product_variant_id' => $variantB->id, 'quantity' => 1],
    ]);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->patchJson("/api/v1/orders/{$orderB->id}", ['status' => 'cancelled'])
        ->assertNotFound();

    expect($orderB->fresh()->status)->not->toBe('cancelled');
});
