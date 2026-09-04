<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function roleUser(\App\Models\Tenant $tenant, string $role): User
{
    return User::create([
        'tenant_id' => $tenant->id,
        'name' => ucfirst($role),
        'email' => "{$role}@shop.test",
        'password' => Hash::make('password'),
        'role' => $role,
    ]);
}

test('a cashier can take POS sales and dispatch, but not refund', function () {
    [$tenant, $owner] = makeTenantUser();
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();
    $provider = createDeliveryProviderForTenant($tenant);
    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ]);

    $token = roleUser($tenant, 'cashier')->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/dispatch", ['delivery_provider_id' => $provider->id])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'customer_cancelled'])
        ->assertStatus(403)
        ->assertJsonPath('required_role', 'manager');
});

test('a cashier never sees buying_price', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, variantOverrides: ['buying_price' => 999.99]);

    $token = roleUser($tenant, 'cashier')->createToken('t')->plainTextToken;

    $body = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertOk()
        ->json();

    expect(json_encode($body))->not->toContain('999.99')
        ->and($body['data'][0]['variants'][0])->not->toHaveKey('buying_price')
        ->and($body['data'][0]['variants'][0])->toHaveKey('selling_price');
});

test('a manager does see buying_price', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, variantOverrides: ['buying_price' => 999.99]);

    $token = roleUser($tenant, 'manager')->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.variants.0.buying_price', '999.99');
});

test('a cashier cannot edit the catalogue or the shop profile', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = roleUser($tenant, 'cashier')->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect($product->fresh()->name)->not->toBe('Renamed')
        ->and($tenant->fresh()->name)->not->toBe('Hijacked');
});

test('a manager can edit the catalogue but not billing or the shop profile', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $token = roleUser($tenant, 'manager')->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertStatus(403)
        ->assertJsonPath('required_role', 'owner');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['name' => 'Hijacked'])
        ->assertStatus(403);
});

test('role refusal is 403, not the 402 the admin app renders as an upgrade', function () {
    [$tenant] = makeTenantUser();
    $token = roleUser($tenant, 'cashier')->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'insufficient_role');
});
