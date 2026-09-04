<?php

use App\Models\Order;
use App\Models\StockMovement;

/**
 * Preorder / backorder.
 *
 * The mechanism under test is a per-variant permission to sell below zero
 * (ProductVariant::$allow_preorder), NOT a second counter and NOT
 * track_stock = false. So most of these assertions are really about one
 * thing: that a negative current_stock stays a coherent, reconcilable
 * number rather than becoming corruption the rest of the system has to
 * work around.
 */
test('a preorder variant can be sold past zero and the ledger stays coherent', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 2,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 5]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertCreated();

    // 2 on hand, 5 sold: the shop now owes 3.
    expect((float) $variant->fresh()->current_stock)->toBe(-3.0);

    // The ledger records the real post-sale balance, not a floored zero —
    // that's what makes the backlog reconcilable later.
    $movement = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'sale')
        ->firstOrFail();

    expect((float) $movement->quantity)->toBe(-5.0)
        ->and((float) $movement->balance_after)->toBe(-3.0);
});

test('a variant without allow_preorder is still refused when stock runs out', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 2,
        'allow_preorder' => false,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 5]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertStatus(422);

    // The whole order rolled back, so the stock it would have taken is
    // still on the shelf.
    expect((float) $variant->fresh()->current_stock)->toBe(2.0)
        ->and(Order::count())->toBe(0);
});

test('the order line snapshots that it was a preorder and what wait was promised', function () {
    [$tenant, $user] = makeTenantUser();

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 21,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    $item = $order->items->first();

    expect((bool) $item->is_preorder)->toBeTrue()
        ->and($item->preorder_lead_time_days)->toBe(21);

    // Changing the variant's lead time afterwards must not rewrite what
    // this customer was actually told — same snapshot rule as unit_price.
    $variant->update(['preorder_lead_time_days' => 60]);

    expect($item->fresh()->preorder_lead_time_days)->toBe(21);
});

test('allow_preorder is a permission, not a mode: a line that ships today is not a preorder', function () {
    [$tenant, $user] = makeTenantUser();

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 10,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 3],
    ]);

    $item = $order->items->first();

    expect((bool) $item->is_preorder)->toBeFalse()
        ->and($item->preorder_lead_time_days)->toBeNull()
        ->and((float) $variant->fresh()->current_stock)->toBe(7.0);
});

test('an untracked variant is never treated as a preorder', function () {
    [$tenant, $user] = makeTenantUser();

    $variant = createProductForTenant($tenant, variantOverrides: [
        'track_stock' => false,
        'current_stock' => 0,
        'allow_preorder' => true,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 4],
    ]);

    // A made-to-order item has no backlog — nothing was deducted, so
    // nothing is owed.
    expect((bool) $order->items->first()->is_preorder)->toBeFalse()
        ->and((float) $variant->fresh()->current_stock)->toBe(0.0);
});

test('receiving the shipment clears the backlog with no special handling', function () {
    [$tenant, $user] = makeTenantUser();

    $product = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
    ]);
    $variant = $product->variants->first();

    createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 7],
    ]);

    expect((float) $variant->fresh()->current_stock)->toBe(-7.0);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/variants/{$variant->id}/restock", [
            'quantity' => 10,
        ])
        ->assertOk();

    // -7 + 10 = 3. The ordinary increment resolves the backlog; nothing
    // anywhere needed to know these units were owed.
    expect((float) $variant->fresh()->current_stock)->toBe(3.0);

    $purchase = StockMovement::where('product_variant_id', $variant->id)
        ->where('type', 'purchase')
        ->firstOrFail();

    expect((float) $purchase->balance_after)->toBe(3.0);
});

test('cancelling a preorder shrinks the backlog instead of inflating stock', function () {
    [$tenant, $user] = makeTenantUser();

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 7],
    ]);

    expect((float) $variant->fresh()->current_stock)->toBe(-7.0);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'supplier_delay',
        ])
        ->assertOk()
        ->assertJsonPath('data.cancellation_reason_label', 'Supplier delayed or cancelled');

    // Back toward zero, not up to 7: the shop never had these units, so
    // returning them must not invent stock that doesn't exist.
    expect((float) $variant->fresh()->current_stock)->toBe(0.0);
});

test('the storefront advertises the wait before the customer commits', function () {
    [$tenant] = makeTenantUser();

    createProductForTenant($tenant, ['name' => 'Imported Phone'], [
        'slug' => 'preorder-phone',
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
    ]);

    $this->getJson('/api/v1/public/products/preorder-phone')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'preorder')
        ->assertJsonPath('data.variants.0.preorder_lead_time_days', 14);
});

test('an out-of-stock variant without preorder still reads as out of stock', function () {
    [$tenant] = makeTenantUser();

    createProductForTenant($tenant, ['name' => 'Sold Out'], [
        'slug' => 'no-preorder-here',
        'current_stock' => 0,
        'allow_preorder' => false,
        'preorder_lead_time_days' => 14,
    ]);

    $this->getJson('/api/v1/public/products/no-preorder-here')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'out_of_stock')
        // The lead time is withheld unless the item is actually on
        // preorder, so a client can't render a wait on something nobody
        // can order.
        ->assertJsonPath('data.variants.0.preorder_lead_time_days', null);
});

test('a preorder variant that still has stock reads as in stock, not preorder', function () {
    [$tenant] = makeTenantUser();

    createProductForTenant($tenant, ['name' => 'Has Some'], [
        'slug' => 'preorder-but-stocked',
        'current_stock' => 5,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
    ]);

    $this->getJson('/api/v1/public/products/preorder-but-stocked')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'in_stock')
        ->assertJsonPath('data.variants.0.preorder_lead_time_days', null);
});

test('a mixed cart reports the longest wait and marks only the waiting line', function () {
    [$tenant, $user] = makeTenantUser();

    $inStock = createProductForTenant($tenant, ['name' => 'Phone Case'], [
        'sku' => 'CASE-1', 'current_stock' => 20,
    ])->variants->first();

    $slow = createProductForTenant($tenant, ['name' => 'Phone'], [
        'sku' => 'PHONE-1',
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 30,
    ])->variants->first();

    $quick = createProductForTenant($tenant, ['name' => 'Charger'], [
        'sku' => 'CHG-1',
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 7,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $inStock->id, 'quantity' => 1],
        ['product_variant_id' => $slow->id, 'quantity' => 1],
        ['product_variant_id' => $quick->id, 'quantity' => 1],
    ]);

    $order->load('items');

    expect($order->hasPreorderItems())->toBeTrue()
        // The parcel can only leave once the slowest item lands.
        ->and($order->preorderReadyBy()->toDateString())
        ->toBe($order->created_at->copy()->addDays(30)->toDateString());

    $byName = $order->items->keyBy('product_name');

    expect((bool) $byName['Phone Case']->is_preorder)->toBeFalse()
        ->and((bool) $byName['Phone']->is_preorder)->toBeTrue()
        ->and((bool) $byName['Charger']->is_preorder)->toBeTrue();

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.has_preorder_items', true);
});

test('the order list flags waiting orders without loading every cart', function () {
    [$tenant, $user] = makeTenantUser();

    $normal = createProductForTenant($tenant, ['name' => 'Normal'], [
        'sku' => 'N-1', 'current_stock' => 10,
    ])->variants->first();

    $preorder = createProductForTenant($tenant, ['name' => 'Waiting'], [
        'sku' => 'W-1', 'current_stock' => 0, 'allow_preorder' => true,
    ])->variants->first();

    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $normal->id, 'quantity' => 1]]);
    createPosOrderForTenant($tenant, $user, [['product_variant_id' => $preorder->id, 'quantity' => 1]]);

    $token = $user->createToken('t')->plainTextToken;

    $body = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->json('data');

    expect(collect($body)->pluck('has_preorder_items')->sort()->values()->all())
        ->toBe([false, true]);
});

test('the dashboard reports the backlog separately from low stock', function () {
    [$tenant, $user] = makeTenantUser();

    // Low but positive: needs reordering soon.
    createProductForTenant($tenant, ['name' => 'Running Low'], [
        'sku' => 'LOW-1', 'current_stock' => 2, 'low_stock_threshold' => 5,
    ]);

    // Oversold: customers are already waiting.
    $preorder = createProductForTenant($tenant, ['name' => 'Oversold'], [
        'sku' => 'OVER-1',
        'current_stock' => 0,
        'low_stock_threshold' => 5,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
    ])->variants->first();

    createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $preorder->id, 'quantity' => 4],
    ]);

    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        // Counted once each, in the bucket that describes the action the
        // shop actually has to take.
        ->assertJsonPath('data.low_stock_variant_count', 1)
        ->assertJsonPath('data.preorder_backlog_variant_count', 1)
        ->assertJsonPath('data.preorder_backlog_units', 4)
        ->assertJsonPath('data.preorder_backlog_variants.0.product_name', 'Oversold')
        ->assertJsonPath('data.preorder_backlog_variants.0.units_owed', 4)
        ->assertJsonPath('data.preorder_backlog_variants.0.preorder_lead_time_days', 14);
});

test('tenant A preorder backlog is invisible to tenant B', function () {
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Only tenant A oversells. The backlog is a cross-table aggregate, so
    // a missing tenant scope would surface as a wrong NUMBER rather than a
    // 404 — silent, and invisible to per-resource tests.
    $variantA = createProductForTenant($tenantA, ['name' => 'A Backorder'], [
        'sku' => 'A-1', 'current_stock' => 0, 'allow_preorder' => true,
    ])->variants->first();

    createPosOrderForTenant($tenantA, $userA, [
        ['product_variant_id' => $variantA->id, 'quantity' => 9],
    ]);

    createProductForTenant($tenantB, ['name' => 'B Normal'], [
        'sku' => 'B-1', 'current_stock' => 10,
    ]);

    $tokenB = $userB->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.preorder_backlog_variant_count', 0)
        ->assertJsonPath('data.preorder_backlog_units', 0)
        ->assertJsonCount(0, 'data.preorder_backlog_variants');
});

test('an implausible lead time is rejected', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [
            'name' => 'Slow Boat',
            'variant' => [
                'sku' => 'SLOW-1',
                'buying_price' => 100,
                'selling_price' => 150,
                'allow_preorder' => true,
                'preorder_lead_time_days' => 400,
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('variant.preorder_lead_time_days');
});

test('preorder settings round-trip through the variant endpoints', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'allow_preorder' => true,
            'preorder_lead_time_days' => 14,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_preorder', true)
        ->assertJsonPath('data.preorder_lead_time_days', 14);
});

/*
|--------------------------------------------------------------------------
| Prepayment on preorder
|--------------------------------------------------------------------------
|
| A shop can refuse to send goods it hasn't got yet against a promise to
| pay later. The rule is per variant, because the risk scales with the
| item — a phone case goes COD, an imported phone doesn't.
*/

test('cash on delivery is refused for a preorder line that requires prepayment', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 21,
        'preorder_deposit_percent' => 100,
    ])->variants->first();

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

    // The whole order rolled back — no order row, and no stock deducted
    // for a sale that was refused.
    expect(Order::count())->toBe(0)
        ->and((float) $variant->fresh()->current_stock)->toBe(0.0)
        ->and(StockMovement::count())->toBe(0);
});

test('a prepaid method is accepted for the same preorder line', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'qr_transfer']);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'qr_transfer',
        ])
        ->assertCreated();

    // qr_transfer has no gateway at all, yet is paid up front — the rule
    // is about WHEN the money arrives, not who processes it.
    expect((float) $variant->fresh()->current_stock)->toBe(-2.0);
});

test('cash on delivery still works when the same variant has stock', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 5,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ])->variants->first();

    // The flag gates preorder LINES, not the variant. With stock on hand
    // this ships today, so there's nothing to prepay for.
    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 2]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertCreated();

    expect((float) $variant->fresh()->current_stock)->toBe(3.0);
});

test('a preorder without the flag still accepts cash on delivery', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 0,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertCreated();

    expect((float) $variant->fresh()->current_stock)->toBe(-1.0);
});

test('one prepay-required line refuses the whole mixed cart', function () {
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant);

    $inStock = createProductForTenant($tenant, ['name' => 'Phone Case'], [
        'sku' => 'CASE-9', 'current_stock' => 20,
    ])->variants->first();

    $strict = createProductForTenant($tenant, ['name' => 'Imported Phone'], [
        'sku' => 'PHONE-9',
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ])->variants->first();

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [
                ['product_variant_slug' => $inStock->slug, 'quantity' => 1],
                ['product_variant_slug' => $strict->slug, 'quantity' => 1],
            ],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'fulfillment_type' => 'delivery',
            'delivery_address' => ['full_address' => 'No. 5, Yangon'],
            'payment_method' => 'cod',
        ])
        ->assertStatus(422);

    // An order carries one payment method, so there's no way to part-pay
    // one line and defer the other. The in-stock line's deduction must
    // roll back with everything else.
    expect(Order::count())->toBe(0)
        ->and((float) $inStock->fresh()->current_stock)->toBe(20.0);
});

test('a POS preorder is unaffected, since it is paid at the counter', function () {
    [$tenant, $user] = makeTenantUser();

    $variant = createProductForTenant($tenant, variantOverrides: [
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ])->variants->first();

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 3],
    ]);

    expect($order->payment_status)->toBe('paid')
        ->and((bool) $order->items->first()->is_preorder)->toBeTrue()
        ->and((float) $variant->fresh()->current_stock)->toBe(-3.0);
});

test('the storefront tells checkout when prepayment is required', function () {
    [$tenant] = makeTenantUser();

    createProductForTenant($tenant, ['name' => 'Imported Phone'], [
        'slug' => 'prepay-phone',
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ]);

    // Withheld once the item is in stock, same as the lead time — the
    // rule only applies to a line that is actually waiting.
    createProductForTenant($tenant, ['name' => 'Stocked Phone'], [
        'slug' => 'stocked-phone',
        'sku' => 'STOCKED-1',
        'current_stock' => 5,
        'allow_preorder' => true,
        'preorder_deposit_percent' => 100,
    ]);

    $this->getJson('/api/v1/public/products/prepay-phone')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'preorder')
        ->assertJsonPath('data.variants.0.preorder_deposit_percent', 100);

    $this->getJson('/api/v1/public/products/stocked-phone')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'in_stock')
        ->assertJsonPath('data.variants.0.preorder_deposit_percent', null);
});

test('the prepayment flag round-trips through the variant endpoint', function () {
    [$tenant, $user] = makeTenantUser();
    $product = createProductForTenant($tenant);
    $variant = $product->variants->first();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
            'allow_preorder' => true,
            'preorder_deposit_percent' => 100,
        ])
        ->assertOk()
        ->assertJsonPath('data.preorder_deposit_percent', 100);
});
