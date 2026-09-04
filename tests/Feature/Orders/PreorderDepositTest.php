<?php

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentEventType;
use App\Services\Payments\WebhookProcessor;

/**
 * "Preorder half prepaid" — the arrangement shops in this market actually
 * advertise, and the one the old all-or-nothing flag could not express.
 *
 * It exists because both extremes fail on an imported item: a customer won't
 * wire the full price of a 668,000 MMK shoe to a shop they found on Facebook,
 * and the shop won't front that to a Bangkok showroom on a stranger's promise.
 * A deposit splits the risk, which is the whole point.
 */
function depositShop(int $percent, float $price = 600000): array
{
    // Unique, so a test can build more than one shop in a single run.
    $unique = Illuminate\Support\Str::random(6);

    [$tenant] = makeTenantUser(
        userOverrides: ['email' => "owner-{$unique}@shop.test"],
        tenantOverrides: [
            'currency' => 'MMK',
            'slug' => "shop-{$unique}",
            'owner_email' => "owner-{$unique}@shop.test",
        ],
    );
    enablePaymentMethodForTenant($tenant, ['method' => 'qr_transfer']);
    enablePaymentMethodForTenant($tenant, ['method' => 'cod']);

    $variant = createProductForTenant($tenant, ['name' => 'Imported Shoe'], [
        'selling_price' => $price,
        'current_stock' => 0,
        'allow_preorder' => true,
        'preorder_lead_time_days' => 14,
        'preorder_deposit_percent' => $percent,
    ])->variants->first();

    return [$tenant, $variant];
}

// ---------------------------------------------------------------------------
// The deposit itself
// ---------------------------------------------------------------------------

test('a half-prepaid preorder line snapshots half the line total', function () {
    [$tenant, $variant] = depositShop(50);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'qr_transfer']);

    $item = $order->items()->first();

    expect($item->is_preorder)->toBeTrue()
        ->and((float) $item->deposit_amount)->toBe(300000.0)
        ->and($order->depositDue())->toBe(300000.0)
        // What the customer is charged NOW is the deposit, not the total.
        ->and($order->amountDueNow())->toBe(300000.0)
        ->and($order->balanceDue())->toBe((float) $order->total);
});

/**
 * A line with stock in hand ships today and asks for nothing up front,
 * whatever percentage the shop has set for when it runs out. The deposit
 * follows the ACTUAL balance, exactly as is_preorder does.
 */
test('an in-stock line carries no deposit even when the variant sets one', function () {
    [$tenant, $variant] = depositShop(50);
    $variant->forceFill(['current_stock' => 5])->save();

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'cod']);

    expect($order->items()->first()->is_preorder)->toBeFalse()
        ->and((float) $order->items()->first()->deposit_amount)->toBe(0.0)
        ->and($order->requiresDeposit())->toBeFalse()
        // No deposit, so the whole total is what a prepaid method would take.
        ->and($order->amountDueNow())->toBe((float) $order->total);
});

test('a mixed cart asks a deposit only on the line that is waiting', function () {
    [$tenant, $preorderVariant] = depositShop(50, price: 600000);
    $inStock = createProductForTenant($tenant, ['name' => 'Phone Case'], [
        'selling_price' => 10000, 'current_stock' => 10,
    ])->variants->first();

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $preorderVariant->slug, 'quantity' => 1],
        ['product_variant_slug' => $inStock->slug, 'quantity' => 1],
    ], ['payment_method' => 'qr_transfer']);

    // Half the shoe, nothing on the case.
    expect($order->depositDue())->toBe(300000.0)
        ->and((float) $order->total)->toBe(610000.0);
});

/**
 * Snapshotted like unit_price: a shop that changes its terms next week must
 * not retroactively change what this customer was asked for.
 */
test('changing the variant percentage never rewrites an existing order', function () {
    [$tenant, $variant] = depositShop(50);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'qr_transfer']);

    $variant->forceFill(['preorder_deposit_percent' => 100])->save();

    expect($order->fresh()->depositDue())->toBe(300000.0);
});

// ---------------------------------------------------------------------------
// Which payment methods a deposit allows
// ---------------------------------------------------------------------------

/**
 * ANY deposit needs a method that collects something at the moment of
 * ordering. Cash on delivery collects nothing then, so "half now" is exactly
 * as impossible on it as "all now" — the percentage decides HOW MUCH is taken,
 * never WHETHER the method can take it.
 */
test('cash on delivery is refused for any deposit, not just a full one', function () {
    foreach ([50, 100] as $percent) {
        [$tenant, $variant] = depositShop($percent);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/v1/public/orders', [
                'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
                'customer_name' => 'Aye Aye',
                'customer_phone' => '09987654321',
                'payment_method' => 'cod',
                'fulfillment_type' => 'pickup',
            ])
            ->assertStatus(422);
    }
});

test('a zero deposit still allows cash on delivery', function () {
    [$tenant, $variant] = depositShop(0);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->postJson('/api/v1/public/orders', [
            'items' => [['product_variant_slug' => $variant->slug, 'quantity' => 1]],
            'customer_name' => 'Aye Aye',
            'customer_phone' => '09987654321',
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
        ])
        ->assertCreated();
});

/**
 * A POS sale has no payment method — it is money already in the till — so the
 * deposit rule cannot apply to it.
 */
test('a counter sale is unaffected by deposits', function () {
    [$tenant, $variant] = depositShop(100);
    [, $user] = [$tenant, $tenant->users()->first()];

    $order = createPosOrderForTenant($tenant, $user, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    expect($order->payment_status)->toBe('paid');
});

// ---------------------------------------------------------------------------
// Money arriving in two parts
// ---------------------------------------------------------------------------

/**
 * A deposit and its later balance are two payments against one order, so how
 * much has arrived is a SUM from the ledger — never a flag. Same reasoning as
 * the stock ledger.
 */
test('paying the deposit marks the order partial, not paid', function () {
    [$tenant, $variant] = depositShop(50);
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe']);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'card']);

    app()->instance('tenant', $tenant);
    Payment::create([
        'order_id' => $order->id, 'gateway' => 'stripe', 'amount' => $order->amountDueNow(),
        'status' => 'pending', 'transaction_ref' => 'cs_deposit',
    ]);
    app()->forgetInstance('tenant');

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_deposit',
        amount: 300000.0,
    ));

    $order = $order->fresh();

    expect($order->payment_status)->toBe('partial')
        // status tracks the COMMERCIAL lifecycle: the shop still has to source
        // the goods and collect the rest, so this is not a completed sale.
        ->and($order->status)->toBe('pending')
        ->and($order->amountPaid())->toBe(300000.0)
        ->and($order->balanceDue())->toBe(300000.0);
});

test('the balance arriving later settles the order', function () {
    [$tenant, $variant] = depositShop(50);
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe']);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'card']);

    app()->instance('tenant', $tenant);
    foreach (['cs_deposit', 'cs_balance'] as $ref) {
        Payment::create([
            'order_id' => $order->id, 'gateway' => 'stripe', 'amount' => 300000,
            'status' => 'pending', 'transaction_ref' => $ref,
        ]);
    }
    app()->forgetInstance('tenant');

    $processor = app(WebhookProcessor::class);
    foreach (['cs_deposit', 'cs_balance'] as $ref) {
        $processor->process('stripe', new PaymentEvent(
            type: PaymentEventType::Succeeded, transactionRef: $ref, amount: 300000.0,
        ));
    }

    $order = $order->fresh();

    expect($order->amountPaid())->toBe(600000.0)
        ->and($order->payment_status)->toBe('paid')
        ->and($order->status)->toBe('paid')
        ->and($order->balanceDue())->toBe(0.0);
});

/**
 * The webhook validates against what was CHARGED. Comparing a deposit to the
 * full total would flag every legitimate half-payment as a mismatch.
 */
test('a deposit-sized payment is not treated as an amount mismatch', function () {
    [$tenant, $variant] = depositShop(50);
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe']);

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 1],
    ], ['payment_method' => 'card']);

    app()->instance('tenant', $tenant);
    $payment = Payment::create([
        'order_id' => $order->id, 'gateway' => 'stripe', 'amount' => 300000,
        'status' => 'pending', 'transaction_ref' => 'cs_deposit',
    ]);
    app()->forgetInstance('tenant');

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded, transactionRef: 'cs_deposit', amount: 300000.0,
    ));

    expect($payment->fresh()->status)->toBe('success');
});

// ---------------------------------------------------------------------------
// What the customer sees before committing
// ---------------------------------------------------------------------------

/**
 * Finding out at the payment step that half is due is the same surprise as
 * finding out about the wait after paying — the thing the whole preorder
 * design exists to prevent.
 */
test('the storefront states the deposit before checkout', function () {
    [$tenant, $variant] = depositShop(50);

    $this->getJson("/api/v1/public/products/{$variant->slug}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'preorder')
        ->assertJsonPath('data.variants.0.preorder_deposit_percent', 50)
        ->assertJsonPath('data.variants.0.preorder_lead_time_days', 14);
});

test('the deposit is withheld while the item is in stock', function () {
    [$tenant, $variant] = depositShop(50);
    $variant->forceFill(['current_stock' => 5])->save();

    $this->getJson("/api/v1/public/products/{$variant->slug}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', 'in_stock')
        // Nothing is due up front on something that ships today.
        ->assertJsonPath('data.variants.0.preorder_deposit_percent', null);
});
