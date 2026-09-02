<?php

use App\Models\User;
use App\Notifications\SubscriptionCancelled;
use App\Notifications\SubscriptionPaymentFailed;
use App\Notifications\SubscriptionPaymentReceived;
use App\Notifications\SubscriptionPaymentReviewed;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Notification;

/**
 * Every billing outcome tells the shop. Before this the Stripe rail was
 * completely silent — a shop paying by card got no email and not even a bell
 * entry — and a rejected transfer only surfaced if the owner happened to look.
 */
beforeEach(function () {
    config()->set('billing.currencies.THB.manual', [
        'bank_name' => 'Bangkok Bank', 'account_name' => 'Shop SaaS',
        'account_number' => 'TH-1', 'instructions' => null,
    ]);
    config()->set('billing.stripe.webhook_secret', BILLING_SECRET);
});

// ---------------------------------------------------------------------------
// Delivery shape
// ---------------------------------------------------------------------------

/**
 * The bell must keep working when no worker is running, which is this
 * project's normal state — only the slow channel is deferred.
 */
test('mail is queued but the in-app notification is not', function () {
    [$tenant] = makeTenantUser();
    $notification = new SubscriptionPaymentFailed($tenant->subscription);

    expect($notification->via($tenant->users()->first()))->toBe(['database', 'mail'])
        ->and($notification->viaConnections())->toBe([
            'mail' => 'database',
            'database' => 'sync',
        ])
        // Sent from inside the transaction that settles a payment; without
        // this a worker could email "payment confirmed" for a ruling that
        // then rolled back.
        ->and($notification->afterCommit)->toBeTrue();
});

// ---------------------------------------------------------------------------
// The manual rail
// ---------------------------------------------------------------------------

test('approving a transfer notifies the shop', function () {
    Notification::fake();

    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => 'manual']);
    $invoice = createTransferInvoice($tenant, ['plan' => 'pro']);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")
        ->assertOk();

    Notification::assertSentTo(
        $tenant->users()->first(),
        SubscriptionPaymentReviewed::class,
        fn ($n, $channels) => in_array('mail', $channels, true),
    );
});

/**
 * The reason is the only part of a rejection the shop can act on, so it has to
 * survive into the email rather than being summarised away.
 */
test('a rejection email quotes the reviewer reason', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    $invoice = createTransferInvoice($tenant, [
        'note' => 'Screenshot shows 500 THB, but 699 was due.',
    ]);

    $mail = (new SubscriptionPaymentReviewed($invoice, false))
        ->toMail($tenant->users()->first());

    expect($mail->introLines)->toContain('Screenshot shows 500 THB, but 699 was due.')
        // ...and tells them the invoice is still payable, which it is.
        ->and(implode(' ', $mail->introLines))->toContain('SUB-'.$invoice->id);
});

/**
 * An upgrade and a scheduled downgrade buy different things, and the shop
 * cannot tell which from the amount alone.
 */
test('the confirmation email states the plan outcome', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'pro', 'gateway' => 'manual', 'current_period_ends_at' => now()->addDays(14)]);

    $invoice = requestTransferForTenant($tenant, 'starter');
    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;
    $mail = (new SubscriptionPaymentReviewed($invoice->fresh(), true, $subscription))
        ->toMail($tenant->users()->first());

    expect(implode(' ', $mail->introLines))
        ->toContain('stay on Pro')
        ->toContain('then move to Starter');
});

// ---------------------------------------------------------------------------
// The card rail — previously silent
// ---------------------------------------------------------------------------

test('a successful card payment notifies the shop', function () {
    Notification::fake();

    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => null]);

    postBillingWebhook(invoicePaidPayload($tenant->id))->assertOk();

    Notification::assertSentTo($tenant->users()->first(), SubscriptionPaymentReceived::class);
});

/**
 * The most time-sensitive email here: access is NOT cut on a decline, so
 * without it the shop's first hint is the day grace runs out.
 */
test('a declined card notifies the shop while they can still fix it', function () {
    Notification::fake();

    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['current_period_ends_at' => now()->subDay()]);

    postBillingWebhook(invoicePaidPayload($tenant->id, ['type' => 'invoice.payment_failed']))
        ->assertOk();

    Notification::assertSentTo($tenant->users()->first(), SubscriptionPaymentFailed::class);

    // And it carries the date that actually matters.
    $mail = (new SubscriptionPaymentFailed($tenant->fresh()->subscription))
        ->toMail($tenant->users()->first());

    expect(implode(' ', $mail->introLines))
        ->toContain('still running')
        ->toContain($tenant->fresh()->subscription->graceEndsAt()->toFormattedDateString());
});

// ---------------------------------------------------------------------------
// Cancellation
// ---------------------------------------------------------------------------

test('cancelling notifies the shop and says what it keeps', function () {
    Notification::fake();

    [$tenant] = makeTenantUser();
    $subscription = subscribeTenant($tenant, ['current_period_ends_at' => now()->addDays(20)]);

    app()->instance('tenant', $tenant);
    try {
        app(SubscriptionService::class)->cancel($subscription);
    } finally {
        app()->forgetInstance('tenant');
    }

    Notification::assertSentTo($tenant->users()->first(), SubscriptionCancelled::class);

    $mail = (new SubscriptionCancelled($tenant->fresh()->subscription))
        ->toMail($tenant->users()->first());

    expect(implode(' ', $mail->introLines))
        ->toContain("won't be charged again")
        // Nothing is deleted, ever — worth saying, because "cancelled" makes
        // people assume their data is going.
        ->toContain('storefront keeps running');
});

/**
 * Stripe deletes a subscription at period end when the shop cancelled through
 * this app, which already emailed them. Telling them again weeks later about
 * something they did would read as a mistake.
 */
test('stripe confirming a cancellation we already knew about does not notify twice', function () {
    Notification::fake();

    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, [
        'status' => 'cancelled',
        'external_subscription_ref' => 'sub_test_123',
    ]);

    postBillingWebhook([
        'id' => 'evt_cancel', 'object' => 'event', 'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_test_123', 'object' => 'subscription', 'customer' => 'cus_test_123',
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ]],
    ])->assertOk();

    Notification::assertNothingSentTo($tenant->users()->first());
});

// ---------------------------------------------------------------------------
// The prerequisite for running a worker at all
// ---------------------------------------------------------------------------

/**
 * A worker process does NOT get a fresh container between jobs. Without this,
 * a job that binds a tenant leaves it bound for whatever runs next on that
 * worker, and the next job reads another shop's data through TenantScope.
 *
 * CLAUDE.md names this a blocking prerequisite of adopting a queue worker.
 */
test('the tenant binding never survives a job', function () {
    [$tenant] = makeTenantUser();

    app()->instance('tenant', $tenant);
    expect(app()->bound('tenant'))->toBeTrue();

    // Dispatched onto the sync CONNECTION, not dispatch_sync(): the latter
    // runs straight through the command bus and fires no queue events, so it
    // would exercise none of the teardown a real worker triggers.
    dispatch(function () {
        // A job that binds a tenant — exactly what a billing or reporting job
        // would legitimately do.
        app()->instance('tenant', User::query()->firstOrFail()->tenant);
    })->onConnection('sync');

    expect(app()->bound('tenant'))->toBeFalse();
});
