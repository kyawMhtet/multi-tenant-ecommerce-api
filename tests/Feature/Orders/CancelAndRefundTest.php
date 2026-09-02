<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;

beforeEach(function () {
    [$this->tenant, $this->user] = makeTenantUser();
    $this->token = $this->user->createToken('t')->plainTextToken;
    $this->variant = createProductForTenant($this->tenant, variantOverrides: ['current_stock' => 10])->variants->first();
});

function asShop()
{
    return test()->withHeader('Authorization', 'Bearer '.test()->token);
}

/** An unpaid QR order — the customer never sent money. */
function unpaidOrder(): Order
{
    return createOnlineOrderForTenant(test()->tenant, [
        ['product_variant_slug' => test()->variant->slug, 'quantity' => 2],
    ], ['payment_method' => 'qr_transfer']);
}

/** A QR order the shop has already confirmed money for. */
function paidOrder(): Order
{
    $order = unpaidOrder();

    app()->instance('tenant', test()->tenant);
    Payment::create([
        'order_id' => $order->id,
        'gateway' => 'manual',
        'amount' => $order->total,
        'status' => 'success',
        'paid_at' => now(),
    ]);
    $order->update(['payment_status' => 'paid', 'status' => 'paid']);
    app()->forgetInstance('tenant');

    return $order->fresh();
}

test('a cancellation reason is required', function () {
    $order = unpaidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cancellation_reason');

    expect(Order::findOrFail($order->id)->status)->toBe('pending');
});

test('an unknown cancellation reason is rejected', function () {
    $order = unpaidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [
        'cancellation_reason' => 'because_i_felt_like_it',
    ])->assertStatus(422)->assertJsonValidationErrors('cancellation_reason');

    expect(Order::findOrFail($order->id)->status)->toBe('pending');
});

test('the reason "other" requires a note, since Other alone explains nothing', function () {
    $order = unpaidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [
        'cancellation_reason' => 'other',
    ])->assertStatus(422)->assertJsonValidationErrors('cancellation_note');

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [
        'cancellation_reason' => 'other',
        'cancellation_note' => 'Driver had an accident on the way',
    ])->assertOk();
});

test('cancelling an unpaid order records the reason and restores stock, with nothing owed', function () {
    $order = unpaidOrder();
    expect($this->variant->fresh()->current_stock)->toEqual(8.0);

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [
        'cancellation_reason' => 'out_of_stock',
        'cancellation_note' => 'Last one was damaged',
    ])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'out_of_stock')
        ->assertJsonPath('data.cancellation_reason_label', 'Out of stock')
        ->assertJsonPath('data.cancellation_note', 'Last one was damaged')
        // Nothing was ever received, so nothing is owed back.
        ->assertJsonPath('data.refund_required', false);

    expect($this->variant->fresh()->current_stock)->toEqual(10.0)
        ->and(Order::findOrFail($order->id)->cancelled_at)->not->toBeNull()
        ->and(Order::findOrFail($order->id)->cancelled_by)->toBe($this->user->id);
});

/**
 * The case that motivated all of this: the customer already transferred
 * money to the shop's own wallet. Cancelling can restore stock, but it
 * cannot un-receive the money — the shop has to send it back by hand.
 */
test('cancelling a PAID order flags a refund as owed rather than claiming one happened', function () {
    $order = paidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", [
        'cancellation_reason' => 'cannot_fulfil',
    ])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        // Still 'paid' — the money genuinely did arrive and hasn't moved.
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.refund_required', true)
        ->assertJsonPath('data.refunded_at', null);

    // Stock comes back regardless of the money.
    expect($this->variant->fresh()->current_stock)->toEqual(10.0);
});

test('marking the refund sent clears the obligation and records who did it', function () {
    $order = paidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'cannot_fulfil'])
        ->assertOk()
        ->assertJsonPath('data.refund_required', true);

    asShop()->postJson("/api/v1/orders/{$order->id}/refund", [
        'refund_note' => 'KBZPay transfer ref 8891, sent 14:20',
    ])->assertOk()
        ->assertJsonPath('data.payment_status', 'refunded')
        ->assertJsonPath('data.refund_required', false)
        ->assertJsonPath('data.refund_note', 'KBZPay transfer ref 8891, sent 14:20');

    $fresh = Order::findOrFail($order->id);
    expect($fresh->refunded_at)->not->toBeNull()
        ->and($fresh->refunded_by)->toBe($this->user->id)
        ->and(Payment::withoutGlobalScopes()->firstOrFail()->status)->toBe('refunded');
});

test('an unpaid order cannot be refunded', function () {
    $order = unpaidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/refund")
        ->assertStatus(422);

    expect(Order::findOrFail($order->id)->refunded_at)->toBeNull();
});

/**
 * Re-cancelling must not restore stock twice, nor overwrite the original
 * reason — that record is what actually happened.
 */
test('cancelling twice keeps the first reason and does not double-restore stock', function () {
    $order = unpaidOrder();

    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'out_of_stock'])->assertOk();
    asShop()->postJson("/api/v1/orders/{$order->id}/cancel", ['cancellation_reason' => 'customer_cancelled'])
        ->assertOk()
        ->assertJsonPath('data.cancellation_reason', 'out_of_stock');

    expect($this->variant->fresh()->current_stock)->toEqual(10.0)
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'return_in')->count())->toBe(1);
});

test('cancel and refund cannot be reached through the generic status update', function () {
    $order = paidOrder();

    asShop()->patchJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    asShop()->patchJson("/api/v1/orders/{$order->id}", ['payment_status' => 'refunded'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_status');

    // Untouched — no reason recorded, no stock returned behind the shop's back.
    $fresh = Order::findOrFail($order->id);
    expect($fresh->status)->toBe('paid')
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($this->variant->fresh()->current_stock)->toEqual(8.0);
});

test('tenant A cannot cancel or refund tenant B order', function () {
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );
    $variantB = createProductForTenant($tenantB, variantOverrides: ['current_stock' => 10])->variants->first();
    $orderB = createOnlineOrderForTenant($tenantB, [
        ['product_variant_slug' => $variantB->slug, 'quantity' => 1],
    ], ['payment_method' => 'cod']);

    asShop()->postJson("/api/v1/orders/{$orderB->id}/cancel", ['cancellation_reason' => 'out_of_stock'])
        ->assertNotFound();
    asShop()->postJson("/api/v1/orders/{$orderB->id}/refund")->assertNotFound();

    expect(Order::withoutGlobalScopes()->findOrFail($orderB->id)->status)->toBe('pending');
});

test('the cancellation reason list is served so clients never keep their own copy', function () {
    $response = asShop()->getJson('/api/v1/orders/cancellation-reasons')->assertOk();

    $codes = collect($response->json('data'))->pluck('code');

    expect($codes)->toContain('out_of_stock')
        ->and($codes)->toContain('other')
        ->and($response->json('data.0.label'))->toBe('Out of stock');
});
