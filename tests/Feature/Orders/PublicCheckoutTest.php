<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Notifications\NewOnlineOrderReceived;
use Illuminate\Support\Facades\Notification;

test('a successful public checkout decrements stock and creates a pending unpaid order, with no auth', function () {
    [$tenant, $user] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
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
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
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

    enablePaymentMethodForTenant($tenantA);
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
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
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
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $response = $this->postJson('/api/v1/public/orders', [
        'items' => [
            ['product_variant_slug' => $variant->slug, 'quantity' => 1],
        ],
        'customer_name' => 'Aye Aye',
        'customer_phone' => '09987654321',
        'fulfillment_type' => 'delivery',
        'delivery_address' => ['full_address' => 'No. 5, Yangon'],
        'payment_method' => 'cod',
    ]);

    $response->assertNotFound();

    expect(Order::count())->toBe(0);
});

test('a public checkout with insufficient stock is rejected and creates no order', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
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
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
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
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $checkout = fn () => $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
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

    enablePaymentMethodForTenant($tenantA);
    $product = createProductForTenant($tenantA, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Someone Else',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
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

    enablePaymentMethodForTenant($tenantA);
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
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])->assertCreated();

    Notification::assertNotSentTo($userB, NewOnlineOrderReceived::class);
});

test('the public order response never exposes cost fields', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['buying_price' => 111.11, 'current_stock' => 10]);
    $variant = $product->variants->first();

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $variant->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ]);

    $response->assertCreated();

    $body = $response->json();

    expect(json_encode($body))->not->toContain('111.11')
        ->and($body['data']['items'][0])->not->toHaveKey('unit_cost');
});

// ---------------------------------------------------------------------------
// What the shop has hidden
//
// The read path (StorefrontProductService::findPublicVariant) has always
// checked is_active on variant, product AND tenant, so hiding anything 404s
// the product page. The WRITE path checked none of them, and resolved on slug
// alone — so every link that had ever circulated kept taking orders for
// products the shop had deliberately pulled, deducting real stock.
//
// Slugs are permanent and public by design (they get pasted into chat apps),
// which is exactly why the two paths have to agree.
// ---------------------------------------------------------------------------

test('a deactivated variant cannot be bought, even though its slug still resolves for the shop', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $variant->update(['is_active' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
            'customer_name' => 'Guest',
            'customer_phone' => '09123456789',
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.product_variant_slug');

    // The stock is the real assertion: a 422 that still deducted would be
    // worse than the bug it replaced.
    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $variant->fresh()->current_stock)->toBe(10.0);
});

test('a variant of a deactivated product cannot be bought either', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
    // The variant itself stays active — only the product is pulled, which is
    // how a shop hides a whole listing rather than one size of it.
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $product->update(['is_active' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
            'customer_name' => 'Guest',
            'customer_phone' => '09123456789',
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ])
        ->assertStatus(422);

    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $variant->fresh()->current_stock)->toBe(10.0);
});

test('the checkout refuses exactly what the product page hides', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $product->update(['is_active' => false]);

    // Read path and write path, asserted together — the pair is the invariant,
    // and testing either alone is what let them drift apart.
    $this->getJson("/api/v1/public/products/{$variant->slug}")->assertNotFound();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Guest',
            'customer_phone' => '09123456789',
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ])
        ->assertStatus(422);
});

test('the service refuses a hidden variant even when validation is bypassed', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    $variant->update(['is_active' => false]);

    // Straight at the service, past the Form Request. The duplicated filter in
    // resolveCartLinesBySlug() is the defence-in-depth half: a check that
    // lives only in validation is one route registration away from being gone.
    expect(fn () => createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['fulfillment_type' => 'pickup']))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect((float) $variant->fresh()->current_stock)->toBe(10.0);
});

test('an active variant of an active product is still perfectly sellable', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);
    $variant = $product->variants->first();

    // The guard against over-correcting: it would be easy to filter this so
    // hard that nothing sells at all.
    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
            'customer_name' => 'Guest',
            'customer_phone' => '09123456789',
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ])
        ->assertCreated();

    expect((float) $variant->fresh()->current_stock)->toBe(8.0);
});
