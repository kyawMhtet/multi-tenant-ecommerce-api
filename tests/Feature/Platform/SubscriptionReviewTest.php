<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;

/**
 * Approving a transfer is the manual rail's equivalent of a payment webhook —
 * the only path by which money becomes a paid plan — so it is tested to the
 * same standard: idempotency, and a refusal to touch anything a gateway owns.
 *
 * Each test authenticates as ONE identity over HTTP. Sanctum's guard caches
 * the resolved user for the rest of the test process, so mixing an admin
 * token and a shop token in one test would silently resolve both as the
 * first (see the note on createPosOrderForTenant in Pest.php) — shop-side
 * outcomes are therefore asserted against the models.
 */
test('the pending queue spans every tenant', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'name' => 'Shop A', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'name' => 'Shop B', 'owner_email' => 'b@shop.test'],
    );

    createTransferInvoice($tenantA);
    createTransferInvoice($tenantB);

    $shops = collect(actingAsPlatform()->getJson('/api/v1/platform/billing/pending')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data'))->pluck('shop.name')->sort()->values();

    expect($shops->all())->toBe(['Shop A', 'Shop B']);
});

/**
 * A shop that asked for bank details and went quiet is either a payment that
 * arrived without a screenshot or a shop that needs chasing. Hiding those
 * would make the queue look finished when it isn't — but the actionable ones
 * come first.
 */
test('transfers with no screenshot are listed, after those that have one', function () {
    [$tenant] = makeTenantUser();

    createTransferInvoice($tenant, ['proof_path' => null]);
    createTransferInvoice($tenant, ['proof_path' => 'billing-proofs/real.jpg']);

    $data = actingAsPlatform()->getJson('/api/v1/platform/billing/pending')
        ->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['proof_url'])->not->toBeNull()
        ->and($data[1]['proof_url'])->toBeNull();
});

test('approving settles the invoice and moves the shop onto the plan it paid for', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => null, 'current_period_ends_at' => now()->subDay()]);

    $invoice = createTransferInvoice($tenant, [
        'plan' => 'pro',
        'period_end' => now()->addDays(29),
    ]);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve", [
        'note' => 'Matched bank statement 2026-09-01.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.shop.slug', $tenant->slug);

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->plan)->toBe('pro')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->gateway)->toBe('manual')
        ->and($subscription->current_period_ends_at->toDateString())
        ->toBe($invoice->period_end->toDateString())
        ->and($subscription->allowsWrites())->toBeTrue();

    $settled = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->find($invoice->id);

    expect($settled->paid_at)->not->toBeNull()
        ->and($settled->reviewed_at)->not->toBeNull()
        ->and($settled->reviewed_by)->not->toBeNull();
});

/**
 * The same standard the payment webhook is held to. A double-click, a retried
 * request, or two staff working the queue at once must not grant two months.
 */
test('approving twice is a no-op, not a second month', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['plan' => 'starter']);

    $invoice = createTransferInvoice($tenant, ['period_end' => now()->addDays(30)]);
    $platform = actingAsPlatform();

    $platform->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();
    $firstEnd = $tenant->fresh()->subscription->current_period_ends_at;

    $platform->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    expect($tenant->fresh()->subscription->current_period_ends_at->toDateTimeString())
        ->toBe($firstEnd->toDateTimeString())
        ->and(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});

/**
 * A card invoice belongs to Stripe and is settled by its webhook alone. The
 * same restriction that keeps a shop ticking a box from settling a card
 * payment on the order side — without it, a tired admin could mark a failed
 * charge paid and the platform would carry a shop it was never paid for.
 */
test('staff cannot settle a card invoice by hand', function () {
    [$tenant] = makeTenantUser();
    // Explicitly starter: makeTenantUser() starts the trial on the TOP plan,
    // so "still not pro" would have been true before the request too and
    // proved nothing.
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'stripe']);

    $invoice = createTransferInvoice($tenant, [
        'plan' => 'pro',
        'gateway' => 'stripe',
        'external_ref' => 'in_123',
    ]);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('reason', 'billing_action_unavailable');

    $untouched = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->find($invoice->id);

    expect($tenant->fresh()->subscription->plan)->toBe('starter')
        ->and($untouched->status)->toBe('pending')
        ->and($untouched->paid_at)->toBeNull();
});

/**
 * An approved payment un-cancels: a shop that cancelled and then paid has
 * plainly changed its mind.
 */
test('approving reactivates a cancelled subscription', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, [
        'plan' => 'starter',
        'status' => 'cancelled',
        'cancel_at_period_end' => true,
        'cancelled_at' => now()->subDay(),
        'current_period_ends_at' => now()->subHour(),
    ]);

    expect($tenant->subscription->isReadOnly())->toBeTrue();

    $invoice = createTransferInvoice($tenant, ['period_end' => now()->addDays(30)]);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->status)->toBe('active')
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->cancelled_at)->toBeNull()
        ->and($subscription->isReadOnly())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Rejecting
// ---------------------------------------------------------------------------

/**
 * Left unpaid rather than voided, so the shop can transfer again and upload
 * against the SAME invoice — ManualBillingRail reuses an unpaid one. A dead
 * end here would mean the shop owes a period it can no longer pay for.
 */
test('rejecting leaves the invoice payable and the plan untouched', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['plan' => 'starter']);

    $invoice = createTransferInvoice($tenant);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/reject", [
        'reason' => 'Screenshot shows 500 THB, but 750 was due.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.note', 'Screenshot shows 500 THB, but 750 was due.');

    $rejected = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->find($invoice->id);

    expect($rejected->paid_at)->toBeNull()
        ->and($rejected->reviewed_by)->not->toBeNull()
        // scopeUnpaid() includes 'failed', so the shop can pay this same one.
        ->and(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->unpaid()->count())->toBe(1)
        ->and($tenant->fresh()->subscription->plan)->toBe('starter');
});

/**
 * A shop told only "rejected" cannot act on it, and opens a support ticket
 * asking why — which costs more than typing the sentence would have.
 */
test('a rejection must say why', function () {
    [$tenant] = makeTenantUser();
    $invoice = createTransferInvoice($tenant);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/reject", [])
        ->assertJsonValidationErrors('reason');
});

test('a settled invoice cannot be rejected after the fact', function () {
    [$tenant] = makeTenantUser();
    $invoice = createTransferInvoice($tenant);
    $platform = actingAsPlatform();

    $platform->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $platform->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/reject", [
        'reason' => 'Changed my mind about this one.',
    ])->assertStatus(422);
});

test('an invoice that does not exist is a 404', function () {
    actingAsPlatform()->postJson('/api/v1/platform/billing/invoices/99999/approve')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Telling the shop
// ---------------------------------------------------------------------------

/**
 * Without this the manual rail is silent from the shop's side: they upload a
 * screenshot and then either notice their plan changed or don't.
 */
test('approving tells the shop', function () {
    [$tenant, $user] = makeTenantUser();
    subscribeTenant($tenant, ['plan' => 'starter']);

    $invoice = createTransferInvoice($tenant, ['plan' => 'pro']);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")
        ->assertOk();

    $notification = $user->fresh()->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe('subscription_payment_reviewed')
        ->and($notification->data['approved'])->toBeTrue()
        ->and($notification->data['plan'])->toBe('pro')
        ->and($notification->data['reference'])->toBe('SUB-'.$invoice->id);
});

/**
 * The rejection case matters more than the approval one — a shop told only
 * "rejected" cannot act, so the reason has to travel with the notification.
 */
test('rejecting tells the shop why', function () {
    [$tenant, $user] = makeTenantUser();

    $invoice = createTransferInvoice($tenant);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/reject", [
        'reason' => 'Screenshot shows 500 THB, but 750 was due.',
    ])->assertOk();

    $notification = $user->fresh()->notifications()->first();

    expect($notification->data['approved'])->toBeFalse()
        ->and($notification->data['note'])->toBe('Screenshot shows 500 THB, but 750 was due.');
});

/**
 * The notification is sent inside the same transaction as the ruling, so a
 * rollback must not leave the shop holding news about something that never
 * happened. A card invoice is refused before anything is written.
 */
test('a refused ruling notifies nobody', function () {
    [$tenant, $user] = makeTenantUser();

    $invoice = createTransferInvoice($tenant, ['gateway' => 'stripe', 'external_ref' => 'in_x']);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")
        ->assertStatus(422);

    expect($user->fresh()->notifications()->count())->toBe(0);
});
