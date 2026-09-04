<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\Storage;

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
 * The queue answers ONE question: what must I rule on. A shop that asked for
 * bank details and sent nothing has no decision attached to it, so it belongs
 * on a chase list instead — mixing them meant the queue had to be visually
 * filtered before it could be worked.
 */
test('the queue holds only transfers with a screenshot to look at', function () {
    [$tenant] = makeTenantUser();

    createTransferInvoice($tenant, ['proof_path' => null]);
    $withProof = createTransferInvoice($tenant, ['proof_path' => 'billing-proofs/real.jpg']);

    $queue = actingAsPlatform()->getJson('/api/v1/platform/billing/pending')
        ->assertOk()->json('data');

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['id'])->toBe($withProof->id)
        ->and($queue[0]['proof_url'])->not->toBeNull();
});

/**
 * NOT hidden, though: a shop that transfers and forgets to upload is common on
 * this rail, and the money arriving needs an invoice to land against.
 */
test('shops that asked how to pay and sent nothing are still visible', function () {
    [$tenant] = makeTenantUser();

    $silent = createTransferInvoice($tenant, ['proof_path' => null]);
    createTransferInvoice($tenant, ['proof_path' => 'billing-proofs/real.jpg']);

    $chase = actingAsPlatform()->getJson('/api/v1/platform/billing/awaiting-transfer')
        ->assertOk()->json('data');

    expect($chase)->toHaveCount(1)
        ->and($chase[0]['id'])->toBe($silent->id)
        ->and($chase[0]['proof_url'])->toBeNull();
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

/**
 * An intent raised months ago still quotes a period that has gone by. Handing
 * it back would bill the shop for a month already over, and it would sit on
 * the chase list forever. Cleaned up lazily when the shop returns — the dates
 * already say when it went stale, so no scheduler is involved.
 */
test('an abandoned transfer intent is voided and re-quoted when the shop returns', function () {
    config()->set('billing.currencies.THB.manual', [
        'bank_name' => 'Bangkok Bank', 'account_name' => 'Shop SaaS',
        'account_number' => 'TH-1', 'instructions' => null,
    ]);

    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'manual']);

    $abandoned = createTransferInvoice($tenant, ['plan' => 'starter', 'proof_path' => null]);
    $abandoned->forceFill([
        'created_at' => now()->subDays(config('billing.transfer_intent_expiry_days') + 1),
    ])->save();

    $fresh = requestTransferForTenant($tenant, 'starter');

    expect($fresh->id)->not->toBe($abandoned->id)
        ->and($abandoned->fresh()->status)->toBe('void')
        // The new quote is current, not the dead one.
        ->and($fresh->period_end->isFuture())->toBeTrue();

    // And the chase list shows one shop, not two rows for the same one.
    actingAsPlatform()->getJson('/api/v1/platform/billing/awaiting-transfer')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fresh->id);
});

/**
 * A shop mid-way through paying must not have its reference pulled out from
 * under it — the expiry window is generous precisely because a transfer on
 * this rail genuinely takes days.
 */
test('a recent unpaid intent is still reused, not voided', function () {
    config()->set('billing.currencies.THB.manual', [
        'bank_name' => 'Bangkok Bank', 'account_name' => 'Shop SaaS',
        'account_number' => 'TH-1', 'instructions' => null,
    ]);

    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'manual']);

    $first = requestTransferForTenant($tenant, 'starter');
    $again = requestTransferForTenant($tenant, 'starter');

    expect($again->id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe('pending');
});

/**
 * Rejection is designed as a recoverable state: the invoice stays unpaid so
 * the shop can transfer again and upload against the SAME reference. That
 * recovery was silently broken — 'failed' is excluded from the review queue,
 * and the chase list only matches invoices with no proof at all, so a
 * re-uploaded rejection was visible to nobody and would have waited forever.
 */
test('a rejected transfer comes back to the queue when the shop re-uploads', function () {
    Storage::fake('public');

    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'manual']);
    $invoice = createTransferInvoice($tenant, ['plan' => 'starter']);

    app(App\Services\Platform\SubscriptionReviewService::class)
        ->reject($invoice->id, makePlatformAdmin(), 'Screenshot shows 400 THB, but 499 was due.');

    // Rejected: not work for a reviewer, and not a shop that has sent nothing.
    $review = app(App\Services\Platform\SubscriptionReviewService::class);
    expect($review->pending()->pluck('id'))->not->toContain($invoice->id)
        ->and($review->awaitingTransfer()->pluck('id'))->not->toContain($invoice->id);

    // The shop transfers again and sends a new screenshot.
    $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
        ->postJson("/api/v1/billing/invoices/{$invoice->id}/proof", [
            'proof' => Illuminate\Http\UploadedFile::fake()->image('second.jpg'),
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending');

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe('pending')
        ->and($review->pending()->pluck('id'))->toContain($invoice->id)
        // The previous ruling is kept: a second screenshot for the same wrong
        // amount should be recognisable as such by whoever picks it up.
        ->and($fresh->note)->toBe('Screenshot shows 400 THB, but 499 was due.')
        ->and($fresh->reviewed_by)->not->toBeNull();
});

/**
 * The re-uploaded claim must be settleable, not merely visible — the whole
 * point of putting it back in the queue.
 */
test('a re-uploaded transfer can then be approved normally', function () {
    Storage::fake('public');

    [$tenant, $user] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'manual']);
    $invoice = createTransferInvoice($tenant, ['plan' => 'pro']);

    // One admin, reused: makePlatformAdmin() defaults to a fixed email, and
    // platform_admins.email is unique.
    $admin = makePlatformAdmin();
    $review = app(App\Services\Platform\SubscriptionReviewService::class);

    $review->reject($invoice->id, $admin, 'Wrong recipient account.');

    $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
        ->postJson("/api/v1/billing/invoices/{$invoice->id}/proof", [
            'proof' => Illuminate\Http\UploadedFile::fake()->image('second.jpg'),
        ])->assertSuccessful();

    $review->approve($invoice->id, $admin, 'Confirmed on the statement.');

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($tenant->fresh()->subscription->effectivePlan())->toBe('pro');
});
