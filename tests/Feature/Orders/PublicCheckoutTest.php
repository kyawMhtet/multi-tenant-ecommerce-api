<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Notifications\NewOnlineOrderReceived;
use Illuminate\Support\Facades\Notification;

test('a successful public checkout decrements stock and creates a pending unpaid order, with no auth', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    Notification::fake();

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 3],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.source', 'online')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.cashier_id', null)
        ->assertJsonCount(1, 'data.items');

    Notification::assertSentTo($user, NewOnlineOrderReceived::class);

    expect($variant->fresh()->current_stock)->toEqual(7.0);

    $movement = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'sale')
        ->firstOrFail();

    expect((float) $movement->quantity)->toBe(-3.0)
        ->and($movement->reference_type)->toBe(Order::class)
        ->and($movement->created_by)->toBeNull();

    $customer = Customer::where('phone', '09987654321')->firstOrFail();
    expect($customer->name)->toBe('Aye Aye');

    $order = Order::firstOrFail();
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->cashier_id)->toBeNull();
});

test('a cart line referencing another tenant product_variant_slug is rejected, not silently ignored', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $productA = createProductForTenant($tenantA, variantOverrides: ['current_stock' => 10]);
    $variantA = $productA->variants->first();

    $productB = createProductForTenant($tenantB, variantOverrides: ['current_stock' => 10]);
    $variantB = $productB->variants->first();

    // Claim tenant A via the header, but slip in tenant B's variant slug —
    // this must be rejected, not resolved against tenant B or silently
    // dropped from the cart.
    $response = $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variantA->slug, 'quantity' => 1],
                ['product_variant_slug' => $variantB->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('items.1.product_variant_slug');

    expect(Order::count())->toBe(0)
        ->and($variantA->fresh()->current_stock)->toEqual(10.0)
        ->and($variantB->fresh()->current_stock)->toEqual(10.0)
        ->and(StockMovement::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
});

test('a public checkout with no X-Tenant-Slug header is rejected', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $response = $this->postJson('/api/v1/public/orders', [
        'items' => [
            ['product_variant_slug' => $variant->slug, 'quantity' => 1],
        ],
        'customer_name' => 'Aye Aye',
        'customer_phone' => '09987654321',
    ]);

    $response->assertNotFound();

    expect(Order::count())->toBe(0);
});

test('a public checkout with insufficient stock is rejected and creates no order', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 1]);
    $variant = $product->variants->first();

    Notification::fake();

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 5],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ]);

    $response->assertUnprocessable();

    expect(Order::count())->toBe(0)
        ->and($variant->fresh()->current_stock)->toEqual(1.0);

    // Proves the notify call is genuinely post-commit: if it were wrongly
    // placed before stock deduction finishes inside the transaction, this
    // test's "no order created" assertion above would still pass while
    // silently notifying about an order that doesn't exist.
    Notification::assertNothingSent();
});

test('a returning customer is matched by phone instead of duplicated', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $checkout = fn () => $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ])->assertCreated();

    $checkout();
    $checkout();

    expect(Customer::count())->toBe(1)
        ->and(Order::count())->toBe(2);
});

test('a phone number matching another tenant customer does not attach to them', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    app()->instance('tenant', $tenantB);
    $customerB = Customer::create(['name' => 'Tenant B Regular', 'phone' => '09987654321']);
    app()->forgetInstance('tenant');

    $product = createProductForTenant($tenantA, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Someone Else',
            'customer_phone' => '09987654321',
        ])->assertCreated();

    // ResolveTenant leaves 'tenant' bound to tenant A after the request
    // (by design — see CLAUDE.md on why this is safe under PHP-FPM but
    // must be reset under Octane). Forgetting it here isn't just cleanup:
    // without it, the assertions below would themselves be silently
    // scoped to tenant A, undercounting tenant B's untouched customer.
    app()->forgetInstance('tenant');

    expect(Customer::count())->toBe(2)
        ->and($customerB->fresh()->orders)->toBeEmpty();
});

test('a public checkout only notifies its own tenant\'s users, never another tenant\'s', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $product = createProductForTenant($tenantA, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    Notification::fake();

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ])->assertCreated();

    Notification::assertNotSentTo($userB, NewOnlineOrderReceived::class);
});

test('the public order response never exposes cost fields', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['buying_price' => 111.11, 'current_stock' => 10]);
    $variant = $product->variants->first();

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
        ]);

    $response->assertCreated();

    $body = $response->json();

    expect(json_encode($body))->not->toContain('111.11')
        ->and($body['data']['items'][0])->not->toHaveKey('unit_cost');
});
