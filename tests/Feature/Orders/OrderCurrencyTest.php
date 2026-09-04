<?php

use App\Models\Order;

/**
 * orders.currency used to be a column nothing ever wrote.
 *
 * The migration gives it default('MMK') and neither create path passed the
 * field, so it was not "this order's currency" — it was the constant 'MMK'.
 * Every Thai shop's takings were labelled Kyat, and StripeGateway's
 * `$order->currency ?? $tenant->currency` fallback could never reach its
 * second operand, so a THB shop's Checkout Session was denominated in a
 * currency Stripe does not support at all.
 *
 * Money columns here carry no currency tag of their own, so the snapshot is
 * what makes a historical total interpretable. tenants.currency is immutable
 * for the same reason, which is what makes copying it safe rather than a
 * second source of truth.
 */

test('a POS order is stamped with the shop currency, not the column default', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    expect($order->currency)->toBe('THB');
});

test('a storefront order is stamped with the shop currency', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1],
    ], ['fulfillment_type' => 'pickup']);

    expect($order->currency)->toBe('THB');
});

test('a Kyat shop still records MMK — the default was right, just not chosen', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'MMK']);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    expect($order->currency)->toBe('MMK');
});

test('two shops trading in different currencies do not borrow each other\'s', function () {
    [$thai, $thaiUser] = makeTenantUser(
        userOverrides: ['email' => 'bangkok@shop.test'],
        tenantOverrides: ['slug' => 'bangkok', 'owner_email' => 'bangkok@shop.test', 'currency' => 'THB'],
    );
    [$burmese, $burmeseUser] = makeTenantUser(
        userOverrides: ['email' => 'yangon@shop.test'],
        tenantOverrides: ['slug' => 'yangon', 'owner_email' => 'yangon@shop.test', 'currency' => 'MMK'],
    );

    $thaiProduct = createProductForTenant($thai, variantOverrides: ['current_stock' => 10]);
    $burmeseProduct = createProductForTenant($burmese, variantOverrides: ['current_stock' => 10]);

    $thaiOrder = createPosOrderForTenant($thai, $thaiUser, [
        ['product_variant_id' => $thaiProduct->variants->first()->id, 'quantity' => 1],
    ]);
    $burmeseOrder = createPosOrderForTenant($burmese, $burmeseUser, [
        ['product_variant_id' => $burmeseProduct->variants->first()->id, 'quantity' => 1],
    ]);

    expect($thaiOrder->currency)->toBe('THB')
        ->and($burmeseOrder->currency)->toBe('MMK');
});

test('the order API reports the currency the shop actually trades in', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    // The quiet half of the bug: the admin app labelled every Baht figure
    // "MMK", so a Thai shop's dashboard was wrong in a way nobody would
    // report as a payment failure.
    $this->actingAs($user)
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.currency', 'THB');
});

test('the currency is snapshotted, so it survives even if the shop row changes', function () {
    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    $product = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10]);

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $product->variants->first()->id, 'quantity' => 1],
    ]);

    // UpdateTenantRequest refuses currency changes precisely so this can't
    // happen through the API — but the order carrying its own copy is what
    // makes that a defence in depth rather than the only thing standing
    // between history and being retroactively reinterpreted.
    $tenant->forceFill(['currency' => 'MMK'])->save();

    expect(Order::withoutGlobalScopes()->find($order->id)->currency)->toBe('THB');
});
