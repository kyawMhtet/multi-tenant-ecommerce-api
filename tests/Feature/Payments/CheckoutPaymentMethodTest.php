<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\TenantPaymentMethod;

test('the storefront only sees methods the shop enabled, in the shop\'s order', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe', 'sort_order' => 1]);
    enablePaymentMethodForTenant($tenant, ['method' => 'cod', 'sort_order' => 0]);
    enablePaymentMethodForTenant($tenant, ['method' => 'bank_transfer', 'is_enabled' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/payment-methods')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.method', 'cod')
        ->assertJsonPath('data.0.label', 'Cash on delivery')
        ->assertJsonPath('data.1.method', 'card');
});

test('the public payment methods response never reveals which gateway is behind a method', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe']);

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/payment-methods')
        ->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('gateway')
        ->and($response->json('data.0'))->not->toHaveKey('config')
        ->and(json_encode($response->json()))->not->toContain('stripe');
});

test('tenant A never sees tenant B payment methods', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    enablePaymentMethodForTenant($tenantB, ['method' => 'cod']);

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/public/payment-methods')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('checkout records the chosen method and needs no payment row for cash on delivery', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'cod']);
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertCreated()
        ->assertJsonPath('data.payment_method', 'cod')
        ->assertJsonPath('data.payment_status', 'unpaid')
        // A manual method has nothing to redirect to — the order simply
        // waits for a human, which the client must be told explicitly
        // rather than left to infer from a missing key.
        ->assertJsonPath('payment.type', 'none');

    expect(Payment::count())->toBe(0)
        ->and(Order::firstOrFail()->payment_method)->toBe('cod');
});

test('a method the shop has not enabled is rejected', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'cod']);
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'card',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method');

    expect(Order::count())->toBe(0);
});

test('a method belonging to another shop is rejected', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    enablePaymentMethodForTenant($tenantA, ['method' => 'cod']);
    enablePaymentMethodForTenant($tenantB, ['method' => 'card', 'gateway' => 'stripe']);

    $variant = createProductForTenant($tenantA, variantOverrides: ['current_stock' => 10])->variants->first();

    // Shop B accepts cards; shop A does not. Naming B's method while
    // checking out on A's storefront must not borrow B's configuration.
    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'card',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method');

    expect(Order::count())->toBe(0);
});

test('a disabled method is rejected even though the row still exists', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'cod', 'is_enabled' => false]);
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertStatus(422);

    expect(TenantPaymentMethod::withoutGlobalScopes()->count())->toBe(1)
        ->and(Order::count())->toBe(0);
});
