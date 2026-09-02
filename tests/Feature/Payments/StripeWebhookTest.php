<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentEventType;
use App\Services\Payments\WebhookProcessor;

/**
 * These exercise WebhookProcessor directly rather than posting signed
 * payloads to the route. Signature verification is Stripe's own code and
 * belongs to StripeGateway; what actually needs proving here is the part
 * this app owns — that a verified event moves an order to the right state
 * exactly once, and that a redelivery can't double-apply it.
 *
 * Route-level signature rejection is covered separately below.
 */
function pendingCardOrder(): array
{
    [$tenant] = makeTenantUser();
    enablePaymentMethodForTenant($tenant, ['method' => 'card', 'gateway' => 'stripe']);
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 2],
    ], ['payment_method' => 'card']);

    app()->instance('tenant', $tenant);
    $payment = Payment::create([
        'order_id' => $order->id,
        'gateway' => 'stripe',
        'amount' => $order->total,
        'status' => 'pending',
        'transaction_ref' => 'cs_test_ref_1',
    ]);
    app()->forgetInstance('tenant');

    return [$tenant, $order, $variant, $payment];
}

test('a succeeded event marks the order paid', function () {
    [, $order, $variant, $payment] = pendingCardOrder();

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_test_ref_1',
        amount: (float) $order->total,
    ));

    expect($payment->fresh()->status)->toBe('success')
        ->and($payment->fresh()->paid_at)->not->toBeNull()
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and($order->fresh()->status)->toBe('paid')
        // Stock was already deducted at order creation; being paid must
        // not deduct it a second time.
        ->and($variant->fresh()->current_stock)->toEqual(8.0);
});

test('a redelivered succeeded event does not apply twice', function () {
    [, $order, , $payment] = pendingCardOrder();

    $event = new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_test_ref_1',
        amount: (float) $order->total,
    );

    app(WebhookProcessor::class)->process('stripe', $event);
    $firstPaidAt = $payment->fresh()->paid_at;

    // Providers redeliver routinely — a timeout, a retry, an operator
    // replaying an event. This must be a no-op, not a second payment.
    app(WebhookProcessor::class)->process('stripe', $event);

    expect(Payment::withoutGlobalScopes()->count())->toBe(1)
        ->and($payment->fresh()->paid_at->eq($firstPaidAt))->toBeTrue()
        ->and($order->fresh()->payment_status)->toBe('paid');
});

test('an amount that does not match the order total is refused, not paid', function () {
    [, $order, , $payment] = pendingCardOrder();

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_test_ref_1',
        // A session created for far less than the order is worth.
        amount: 1.00,
    ));

    expect($payment->fresh()->status)->toBe('failed')
        ->and($order->fresh()->payment_status)->toBe('unpaid')
        ->and($order->fresh()->status)->toBe('pending');
});

test('an expired event releases the reserved stock', function () {
    [, $order, $variant, $payment] = pendingCardOrder();

    expect($variant->fresh()->current_stock)->toEqual(8.0);

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Expired,
        transactionRef: 'cs_test_ref_1',
    ));

    expect($payment->fresh()->status)->toBe('failed')
        ->and($order->fresh()->status)->toBe('cancelled')
        // The whole reason expiry is wired to a webhook: an abandoned
        // checkout must give its inventory back.
        ->and($variant->fresh()->current_stock)->toEqual(10.0)
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'return_in')->count())->toBe(1);
});

test('a failed event leaves stock reserved so the customer can retry', function () {
    [, $order, $variant, $payment] = pendingCardOrder();

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Failed,
        transactionRef: 'cs_test_ref_1',
    ));

    expect($payment->fresh()->status)->toBe('failed')
        // Deliberately NOT cancelled: a declined card is not an abandoned
        // order, and pulling stock out from under a customer who is about
        // to try another card would be the wrong call.
        ->and($order->fresh()->status)->toBe('pending')
        ->and($variant->fresh()->current_stock)->toEqual(8.0);
});

test('an unknown transaction reference is ignored rather than erroring', function () {
    [, $order, , $payment] = pendingCardOrder();

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_from_some_other_environment',
        amount: (float) $order->total,
    ));

    expect($payment->fresh()->status)->toBe('pending')
        ->and($order->fresh()->payment_status)->toBe('unpaid');
});

test('the webhook route rejects a payload with no valid signature', function () {
    config(['payments.stripe.webhook_secret' => 'whsec_test_secret']);

    $this->postJson('/api/v1/webhooks/stripe', ['type' => 'checkout.session.completed'])
        ->assertStatus(400);
});

test('the webhook route only accepts known gateways', function () {
    $this->postJson('/api/v1/webhooks/not-a-gateway', [])->assertNotFound();
});

/**
 * The webhook's TenantScope bypass must be the precise form. The blanket
 * withoutGlobalScopes() would also strip SoftDeletingScope, letting a
 * deleted order be resurrected and marked paid by a replayed webhook.
 */
test('a webhook cannot mark a soft-deleted order as paid', function () {
    [$tenant, $order, , $payment] = pendingCardOrder();

    app()->instance('tenant', $tenant);
    $order->delete();
    app()->forgetInstance('tenant');

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Succeeded,
        transactionRef: 'cs_test_ref_1',
        amount: (float) $order->total,
    ));

    $raw = Order::withoutGlobalScope(\App\Models\Concerns\TenantScope::class)
        ->withTrashed()->findOrFail($order->id);

    expect($raw->trashed())->toBeTrue()
        ->and($raw->payment_status)->toBe('unpaid')
        ->and($raw->status)->not->toBe('paid');
});

/**
 * A system cancellation must be as explicable as a human one. Without a
 * reason the shop opens an order that is simply 'cancelled' and can't tell
 * an abandoned checkout from something a staff member did.
 */
test('an expired payment cancels the order with a recorded reason and no user', function () {
    [, $order, $variant, $payment] = pendingCardOrder();

    app(WebhookProcessor::class)->process('stripe', new PaymentEvent(
        type: PaymentEventType::Expired,
        transactionRef: 'cs_test_ref_1',
    ));

    $fresh = $order->fresh();

    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->cancellation_reason)->toBe('payment_expired')
        ->and($fresh->cancelled_at)->not->toBeNull()
        // Nobody did this — the null is what marks it automatic.
        ->and($fresh->cancelled_by)->toBeNull()
        ->and($variant->fresh()->current_stock)->toEqual(10.0);
});
