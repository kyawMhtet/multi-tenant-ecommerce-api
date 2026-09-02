<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;

/**
 * The card rail's counterpart to the platform review queue: the only path by
 * which a Stripe charge becomes a paid plan.
 *
 * Signatures are generated here rather than mocked, so these exercise the real
 * Stripe\Webhook verification. That matters most for the cross-secret test —
 * the entire reason billing has its own endpoint is that Stripe issues a
 * different signing secret per endpoint.
 */
const BILLING_SECRET = 'whsec_billing_test_secret';
const ORDER_SECRET = 'whsec_order_test_secret';

beforeEach(function () {
    config()->set('billing.stripe.webhook_secret', BILLING_SECRET);
    config()->set('payments.stripe.webhook_secret', ORDER_SECRET);
});

function signed(array $payload, string $secret): array
{
    $body = json_encode($payload);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return [$body, "t={$timestamp},v1={$signature}"];
}

function postBillingWebhook(array $payload, string $secret = BILLING_SECRET)
{
    [$body, $signature] = signed($payload, $secret);

    return test()->call(
        'POST',
        '/api/v1/webhooks/billing/stripe',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $body,
    );
}

function invoicePaidPayload(int $tenantId, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'evt_'.\Illuminate\Support\Str::random(8),
        'object' => 'event',
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'in_test_123',
            'object' => 'invoice',
            'customer' => 'cus_test_123',
            'currency' => 'thb',
            'amount_paid' => 75000, // minor units → 750.00 THB
            // The 2025+ shape, where subscription details moved under `parent`.
            'parent' => ['subscription_details' => [
                'subscription' => 'sub_test_123',
                'metadata' => ['tenant_id' => (string) $tenantId, 'plan' => 'pro'],
            ]],
            'lines' => ['data' => [[
                'period' => [
                    'start' => now()->timestamp,
                    'end' => now()->addMonth()->timestamp,
                ],
            ]]],
        ]],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Signature
// ---------------------------------------------------------------------------

test('an unsigned or wrongly signed webhook is refused', function () {
    [$tenant] = makeTenantUser();

    postBillingWebhook(invoicePaidPayload($tenant->id), 'whsec_wrong')->assertStatus(400);

    $this->postJson('/api/v1/webhooks/billing/stripe', invoicePaidPayload($tenant->id))
        ->assertStatus(400);

    expect($tenant->fresh()->subscription->status)->toBe('trialing');
});

/**
 * The single reason billing has its own endpoint and its own secret. If these
 * were shared, either endpoint would accept the other's traffic — subscription
 * events hunting for an order that doesn't exist, and vice versa.
 */
test('a payload signed with the order webhook secret is refused by the billing endpoint', function () {
    [$tenant] = makeTenantUser();

    postBillingWebhook(invoicePaidPayload($tenant->id), ORDER_SECRET)->assertStatus(400);

    expect($tenant->fresh()->subscription->status)->toBe('trialing');
});

test('a payload signed with the billing secret is refused by the order webhook endpoint', function () {
    [$tenant] = makeTenantUser();

    [$body, $signature] = signed(invoicePaidPayload($tenant->id), BILLING_SECRET);

    $this->call(
        'POST', '/api/v1/webhooks/stripe', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $body,
    )->assertStatus(400);
});

// ---------------------------------------------------------------------------
// invoice.paid
// ---------------------------------------------------------------------------

test('a paid invoice moves the shop onto the plan and records the money', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, [
        'plan' => 'starter',
        'status' => 'past_due',
        'gateway' => null,
        'current_period_ends_at' => now()->subDay(),
    ]);

    postBillingWebhook(invoicePaidPayload($tenant->id))->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->plan)->toBe('pro')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->gateway)->toBe('stripe')
        // Learned from the FIRST payment: /billing/subscribe deliberately
        // stores nothing, since asking for money is not receiving it.
        ->and($subscription->external_subscription_ref)->toBe('sub_test_123')
        ->and($subscription->external_customer_ref)->toBe('cus_test_123')
        ->and($subscription->allowsWrites())->toBeTrue();

    $invoice = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->firstOrFail();

    expect($invoice->status)->toBe('paid')
        ->and($invoice->gateway)->toBe('stripe')
        ->and($invoice->external_ref)->toBe('in_test_123')
        // Stripe's figure, converted from minor units — not ours. Proration
        // makes our config the wrong reference here.
        ->and((float) $invoice->amount)->toBe(750.0)
        ->and($invoice->currency)->toBe('THB')
        // Nobody ruled on this one; the gateway confirmed it.
        ->and($invoice->reviewed_by)->toBeNull();
});

/**
 * Providers redeliver routinely. Held to the same standard as the order
 * webhook and the manual approval path: a repeat is a no-op, not a second
 * month.
 */
test('a redelivered invoice grants one period, not two', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => null]);

    postBillingWebhook(invoicePaidPayload($tenant->id))->assertOk();
    $firstEnd = $tenant->fresh()->subscription->current_period_ends_at;

    postBillingWebhook(invoicePaidPayload($tenant->id))->assertOk();

    expect(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->count())->toBe(1)
        ->and($tenant->fresh()->subscription->current_period_ends_at->toDateTimeString())
        ->toBe($firstEnd->toDateTimeString());
});

/**
 * Stripe moved an invoice's subscription details under `parent` in the 2025
 * API versions. Which shape arrives is decided by the account's API version,
 * not by this code, so both must resolve.
 */
test('the pre-2025 invoice shape resolves too', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['currency' => 'THB']);
    subscribeTenant($tenant, ['plan' => 'starter', 'gateway' => null]);

    $payload = invoicePaidPayload($tenant->id);
    unset($payload['data']['object']['parent']);
    $payload['data']['object']['subscription'] = 'sub_test_123';
    $payload['data']['object']['subscription_details'] = [
        'metadata' => ['tenant_id' => (string) $tenant->id, 'plan' => 'pro'],
    ];

    postBillingWebhook($payload)->assertOk();

    expect($tenant->fresh()->subscription->plan)->toBe('pro')
        ->and($tenant->fresh()->subscription->status)->toBe('active');
});

// ---------------------------------------------------------------------------
// Failure and cancellation
// ---------------------------------------------------------------------------

/**
 * A declined card is not a cancellation. Access continues through the grace
 * window — a card that fails on the 1st is often paid by the 3rd, and locking
 * the shop out in between costs it the trade it needs to pay with.
 */
test('a failed payment goes past_due without cutting access', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['current_period_ends_at' => now()->subDay()]);

    $payload = invoicePaidPayload($tenant->id, ['type' => 'invoice.payment_failed']);

    postBillingWebhook($payload)->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->status)->toBe('past_due')
        ->and($subscription->allowsWrites())->toBeTrue()
        ->and($subscription->isInGrace())->toBeTrue();

    // ...and once grace runs out, read-only, by the existing rules.
    $subscription->forceFill(['current_period_ends_at' => now()->subDays(30)])->save();
    expect($subscription->fresh()->isReadOnly())->toBeTrue();
});

test('a deleted stripe subscription is recorded as cancelled', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['external_subscription_ref' => 'sub_test_123']);

    postBillingWebhook([
        'id' => 'evt_cancel',
        'object' => 'event',
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_test_123',
            'object' => 'subscription',
            'customer' => 'cus_test_123',
            'metadata' => ['tenant_id' => (string) $tenant->id, 'plan' => 'pro'],
        ]],
    ])->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->status)->toBe('cancelled')
        ->and($subscription->cancelled_at)->not->toBeNull()
        // Still on Pro until the paid period runs out — cancelling is not a
        // downgrade, and they keep what they bought.
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->allowsWrites())->toBeTrue();
});

/**
 * A trailing retry must not drag a shop that already cancelled back into
 * past_due, which would restart grace on something nobody expects to continue.
 */
test('a late failure does not resurrect a cancelled subscription', function () {
    [$tenant] = makeTenantUser();
    subscribeTenant($tenant, ['status' => 'cancelled', 'external_subscription_ref' => 'sub_test_123']);

    postBillingWebhook(invoicePaidPayload($tenant->id, ['type' => 'invoice.payment_failed']))
        ->assertOk();

    expect($tenant->fresh()->subscription->status)->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// Events we can't or don't act on
// ---------------------------------------------------------------------------

/**
 * 200, not 500. A non-2xx makes Stripe retry forever something that can never
 * succeed.
 */
test('an event for an unknown subscription is accepted and ignored', function () {
    postBillingWebhook(invoicePaidPayload(99999))->assertOk();

    expect(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->count())->toBe(0);
});

test('an event type this app ignores is still a 200', function () {
    [$tenant] = makeTenantUser();

    postBillingWebhook([
        'id' => 'evt_other',
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_test_123', 'object' => 'subscription']],
    ])->assertOk();

    expect($tenant->fresh()->subscription->status)->toBe('trialing');
});
