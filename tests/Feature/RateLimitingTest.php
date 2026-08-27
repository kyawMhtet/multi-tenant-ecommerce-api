<?php

use App\Models\Order;

test('login is throttled after 5 attempts per minute', function () {
    [, $user] = makeTenantUser();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

test('login throttling is scoped per email, not shared across accounts from the same request context', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/login', [
            'email' => $userA->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // Exhausting A's limit must not block B, since both requests share the
    // same test-client IP — the login limiter is keyed by email+IP, not
    // IP alone, precisely so this doesn't happen.
    $this->postJson('/api/v1/login', [
        'email' => $userB->email,
        'password' => 'password',
    ])->assertOk();
});

test('public order creation is throttled after 10 attempts per minute', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 1000]);
    $variant = $product->variants->first();

    $checkout = fn () => $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ]);

    for ($i = 0; $i < 10; $i++) {
        $checkout()->assertCreated();
    }

    $checkout()->assertTooManyRequests();

    // The throttled 11th attempt must not have slipped through and
    // created an order anyway.
    expect(Order::count())->toBe(10);
});
