<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The manual rail is covered end to end here; the Stripe rail is not, for the
 * same reason StripeConnectTest doesn't exercise onboarding — mocking
 * StripeClient deeply enough to be meaningful would assert against a fake
 * rather than against behaviour. What IS pinned down is everything around it:
 * that asking to pay never grants a plan, that a screenshot settles nothing,
 * and that a lapsed shop can still reach the page where it pays.
 */
beforeEach(function () {
    // Without a receiving account the transfer rail reports itself
    // unavailable, which is correct behaviour and useless for testing. Both
    // currencies are configured so the per-country routing is exercised
    // rather than assumed.
    config()->set('billing.currencies.THB.manual', [
        'bank_name' => 'Bangkok Bank',
        'account_name' => 'Shop SaaS Co Ltd',
        'account_number' => 'TH-1234567890',
        'instructions' => null,
    ]);
    config()->set('billing.currencies.MMK.manual', [
        'bank_name' => 'KBZ Bank',
        'account_name' => 'Shop SaaS Myanmar',
        'account_number' => 'MM-9876543210',
        'instructions' => null,
    ]);

    // Pinned here rather than read from config defaults. .env.testing now
    // fixes these too, but stating them at the point of use keeps the
    // assertions readable — 750 appears below, and a reader shouldn't have to
    // open another file to know where it came from.
    config()->set('billing.currencies.THB.plans.starter.amount', 300.0);
    config()->set('billing.currencies.THB.plans.pro.amount', 750.0);
    config()->set('billing.currencies.MMK.plans.starter.amount', 30000.0);
    config()->set('billing.currencies.MMK.plans.pro.amount', 75000.0);
});

/**
 * Currency is explicit in every test here. tenants.currency defaults to MMK
 * at the database level, so a fixture that says nothing is quietly a Myanmar
 * shop — which would make a test asserting Baht pass or fail for reasons
 * nothing in it mentions.
 */
function billingShop(array $subscription = [], string $currency = 'THB'): array
{
    $unique = Illuminate\Support\Str::random(6);

    [$tenant, $user] = makeTenantUser(
        userOverrides: ['email' => "owner-{$unique}@shop.test"],
        tenantOverrides: [
            'currency' => $currency,
            'slug' => "shop-{$unique}",
            'owner_email' => "owner-{$unique}@shop.test",
        ],
    );

    if ($subscription !== []) {
        subscribeTenant($tenant, $subscription);
    }

    return [$tenant, $user->createToken('t')->plainTextToken];
}

test('the billing screen returns current state and the whole plan catalogue', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('data.subscription.plan', 'starter')
        ->assertJsonPath('data.subscription.is_read_only', false)
        ->assertJsonCount(2, 'data.plans');

    $plans = collect($response->json('data.plans'))->keyBy('code');

    expect($plans['starter']['is_current'])->toBeTrue()
        ->and($plans['pro']['is_current'])->toBeFalse()
        ->and($plans['pro']['currency'])->toBe('THB')
        ->and($plans['pro']['features'])->toContain('profit_reports')
        // No Stripe price id configured in tests, so card is correctly
        // reported as unavailable rather than offered as a dead button.
        ->and($plans['pro']['rails'])->toBe(['manual']);
});

/**
 * The reason billing is per-currency at all. A shop inside Myanmar cannot
 * easily wire Baht to a Thai bank — capital controls make it genuinely hard —
 * so billing everyone in one platform currency would have broken the manual
 * rail for exactly the customers it exists for.
 */
test('a Myanmar shop is quoted Kyat and pays into the Myanmar account', function () {
    [, $token] = billingShop(['plan' => 'starter'], currency: 'MMK');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertOk()
        ->assertJsonPath('data.currency', 'MMK');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->assertOk()
        ->assertJsonPath('data.invoice.currency', 'MMK')
        ->assertJsonPath('data.invoice.amount', '75000.00')
        ->assertJsonPath('data.instructions.currency', 'MMK')
        ->assertJsonPath('data.instructions.account_number', 'MM-9876543210');
});

/**
 * Not a missing setting — Stripe does not support MMK at all, so no price id
 * can ever exist for it. Transfer is the only rail a Myanmar shop will ever
 * have, which is why it had to be first-class rather than a fallback.
 */
test('card is structurally unavailable in Kyat even when Stripe is fully configured', function () {
    config()->set('payments.stripe.secret', 'sk_test_configured');
    config()->set('billing.currencies.THB.plans.pro.stripe_price_id', 'price_th_pro');

    // Asked of the manager rather than through two authenticated requests:
    // Sanctum caches the resolved user for the whole test process, so a
    // second call with a different shop's token would silently answer as the
    // first (see the note on createPosOrderForTenant in Pest.php).
    $rails = app(App\Services\Billing\BillingRailManager::class);

    expect($rails->availableFor('pro', 'THB'))->toBe(['stripe', 'manual'])
        ->and($rails->availableFor('pro', 'MMK'))->toBe(['manual']);

    // And the endpoint refuses it outright rather than handing Stripe a null
    // price and surfacing an opaque API error.
    [, $token] = billingShop(['plan' => 'starter'], currency: 'MMK');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'stripe'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'billing_action_unavailable');
});

/**
 * tenants.currency is a good billing key precisely because it is already
 * immutable — refused by UpdateTenantRequest, since money columns carry no
 * currency tag. A shop therefore cannot migrate itself into whichever
 * currency is cheapest.
 */
test('a shop cannot move itself to a cheaper billing currency', function () {
    [$tenant, $token] = billingShop(['plan' => 'starter'], currency: 'THB');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['name' => 'Renamed', 'currency' => 'MMK'])
        ->assertOk();

    expect($tenant->fresh()->currency)->toBe('THB');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')
        ->assertJsonPath('data.currency', 'THB');
});

/**
 * The whole rule of this feature: asking for money is not receiving it.
 */
test('subscribing by bank transfer raises an invoice but does not grant the plan', function () {
    [$tenant, $token] = billingShop(['plan' => 'starter']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->assertOk()
        ->assertJsonPath('data.type', 'transfer')
        ->assertJsonPath('data.url', null)
        ->assertJsonPath('data.instructions.account_number', 'TH-1234567890')
        ->assertJsonPath('data.invoice.plan', 'pro')
        ->assertJsonPath('data.invoice.status', 'pending')
        ->assertJsonPath('data.invoice.currency', 'THB')
        ->assertJsonPath('data.invoice.amount', '750.00');

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->plan)->toBe('starter')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->gateway)->toBe('stripe');
});

test('the transfer carries a reference a human can match the payment to', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->assertOk();

    expect($response->json('data.instructions.reference'))
        ->toBe('SUB-'.$response->json('data.invoice.id'));
});

/**
 * A shop owner clicking twice, or coming back tomorrow to re-read the bank
 * details, must not end up owing two months.
 */
test('asking to pay by transfer twice reuses the same invoice', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->json('data.invoice.id');

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->json('data.invoice.id');

    expect($second)->toBe($first)
        ->and(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});

/**
 * Paying early extends the subscription rather than throwing away the
 * remainder — the same principle as preorderReadyBy() counting from
 * created_at rather than from today.
 */
test('a new period starts where the paid one ends, not today', function () {
    $endsAt = now()->addDays(10);
    [, $token] = billingShop(['plan' => 'pro', 'current_period_ends_at' => $endsAt]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->assertOk();

    $invoice = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->firstOrFail();

    expect($invoice->period_start->toDateString())->toBe($endsAt->toDateString())
        ->and($invoice->period_end->toDateString())->toBe($endsAt->copy()->addMonth()->toDateString());
});

/**
 * A money amount a client can send is a money amount a client can set to
 * zero — the rule that already keeps tenant_id and unit_price out of request
 * bodies.
 */
test('the amount is resolved server-side and cannot be sent by the client', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual', 'amount' => 1])
        ->assertOk()
        ->assertJsonPath('data.invoice.amount', '750.00');
});

test('a rail this deployment has not configured is refused, not offered', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    // No STRIPE_SECRET and no price ids in the test environment.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'stripe'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'billing_action_unavailable');
});

test('an unknown plan or rail is rejected by validation', function () {
    [, $token] = billingShop(['plan' => 'starter']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'enterprise', 'rail' => 'manual'])
        ->assertJsonValidationErrors('plan');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'bitcoin'])
        ->assertJsonValidationErrors('rail');
});

// ---------------------------------------------------------------------------
// Proof of payment
// ---------------------------------------------------------------------------

/**
 * The single most important test in this file. The party uploading the
 * screenshot is the party being billed — if an image could settle an invoice,
 * any shop could grant itself a plan with a file.
 */
test('uploading a transfer screenshot settles nothing', function () {
    Storage::fake('public');
    [$tenant, $token] = billingShop(['plan' => 'starter']);

    $invoiceId = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->json('data.invoice.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/billing/invoices/{$invoiceId}/proof", [
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.paid_at', null)
        ->assertJsonPath('data.reviewed_at', null);

    $invoice = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->firstOrFail();

    expect($invoice->proof_path)->not->toBeNull()
        ->and($invoice->status)->toBe('pending')
        ->and($tenant->fresh()->subscription->plan)->toBe('starter');

    Storage::disk('public')->assertExists($invoice->proof_path);
});

test('the queue a human reviews only contains transfers with proof attached', function () {
    Storage::fake('public');
    [$tenant, $token] = billingShop(['plan' => 'starter']);

    $withProof = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])
        ->json('data.invoice.id');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/billing/invoices/{$withProof}/proof", [
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertSuccessful();

    app()->instance('tenant', $tenant);

    try {
        expect(SubscriptionInvoice::awaitingApproval()->pluck('id')->all())->toBe([$withProof]);
    } finally {
        app()->forgetInstance('tenant');
    }
});

test('another shop invoice is a 404, not a 403', function () {
    Storage::fake('public');
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [, $userB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    $invoice = $tenantA->subscriptionInvoices()->create([
        'subscription_id' => $tenantA->subscription->id,
        'plan' => 'pro', 'amount' => 750, 'currency' => 'THB', 'gateway' => 'manual',
        'period_start' => now(), 'period_end' => now()->addMonth(), 'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer {$userB->createToken('t')->plainTextToken}")
        ->postJson("/api/v1/billing/invoices/{$invoice->id}/proof", [
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Cancelling
// ---------------------------------------------------------------------------

test('cancelling keeps the period already paid for', function () {
    $endsAt = now()->addDays(20);
    [$tenant, $token] = billingShop(['plan' => 'pro', 'current_period_ends_at' => $endsAt]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/cancel')
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_at_period_end', true)
        // Still working, and still on Pro. Cancelling is not a downgrade.
        ->assertJsonPath('data.is_read_only', false)
        ->assertJsonPath('data.plan', 'pro');

    expect($tenant->fresh()->subscription->allowsWrites())->toBeTrue();
});

/**
 * accessEndsAt() reads current_period_ends_at once the status is 'cancelled',
 * so a trialing shop that cancelled would otherwise lose access the same
 * second — taking back something already given.
 */
test('cancelling during a trial keeps the rest of the trial', function () {
    [$tenant, $token] = billingShop();
    $trialEnd = $tenant->subscription->trial_ends_at;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/cancel')
        ->assertSuccessful()
        ->assertJsonPath('data.is_read_only', false);

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->allowsWrites())->toBeTrue()
        ->and($subscription->current_period_ends_at->toDateTimeString())
        ->toBe($trialEnd->toDateTimeString());
});

// ---------------------------------------------------------------------------
// The lapsed shop must be able to pay
// ---------------------------------------------------------------------------

/**
 * If this ever breaks, a shop that misses a payment can never make one again
 * without contacting support. It is the only unrecoverable bug this feature
 * could have.
 */
test('a locked-out shop can still reach billing and pay', function () {
    [$tenant, $token] = billingShop([
        'plan' => 'pro',
        'status' => 'past_due',
        'gateway' => 'manual',
        'current_period_ends_at' => now()->subYear(),
    ]);

    expect($tenant->subscription->isReadOnly())->toBeTrue();

    $auth = $this->withHeader('Authorization', "Bearer {$token}");

    $auth->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.subscription.is_read_only', true);
    $auth->getJson('/api/v1/billing/invoices')->assertOk();
    $auth->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])->assertOk();

    // ...while the catalogue stays locked, which is the whole point.
    $auth->postJson('/api/v1/products', [
        'name' => 'Nope',
        'variant' => ['sku' => 'N-1', 'selling_price' => 100, 'buying_price' => 50],
    ])->assertStatus(402);
});

test('billing requires authentication', function () {
    $this->getJson('/api/v1/billing')->assertUnauthorized();
    $this->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'manual'])->assertUnauthorized();
    $this->postJson('/api/v1/billing/cancel')->assertUnauthorized();
});

/**
 * subscriptions.gateway is not settable through any request — which rail a
 * shop is on is a consequence of money arriving, never a client's claim.
 */
test('a shop cannot declare itself paid through the subscribe endpoint', function () {
    [$tenant, $token] = billingShop(['plan' => 'starter']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', [
            'plan' => 'pro',
            'rail' => 'manual',
            'status' => 'active',
            'current_period_ends_at' => now()->addYears(5)->toDateTimeString(),
        ])->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->plan)->toBe('starter')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->current_period_ends_at->isBefore(now()->addYears(4)))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Why a rail is missing, not just that it is
// ---------------------------------------------------------------------------

/**
 * A bare list of usable rails made two very different situations look
 * identical: "we haven't set this up yet" and "this can never work in your
 * currency". A Myanmar shop was being invited to get in touch about a card
 * option that will never exist.
 */
test('rail_status says WHY a rail is unavailable', function () {
    config()->set('payments.stripe.secret', 'sk_test_configured');
    config()->set('billing.currencies.THB.plans.pro.stripe_price_id', 'price_th_pro');

    [, $thbToken] = billingShop(['plan' => 'starter'], currency: 'THB');

    $plans = collect($this->withHeader('Authorization', "Bearer {$thbToken}")
        ->getJson('/api/v1/billing')->assertOk()->json('data.plans'))->keyBy('code');

    expect($plans['pro']['rail_status'])->toBe(['stripe' => 'available', 'manual' => 'available'])
        // Starter has no price id configured, so card is merely UNSET here —
        // a different answer from Kyat below, and the distinction is the point.
        ->and($plans['starter']['rail_status']['stripe'])->toBe('not_configured');
});

test('Stripe in Kyat is permanent, not pending', function () {
    config()->set('payments.stripe.secret', 'sk_test_configured');

    [, $token] = billingShop(['plan' => 'starter'], currency: 'MMK');

    $plans = collect($this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')->assertOk()->json('data.plans'))->keyBy('code');

    expect($plans['pro']['rail_status']['stripe'])->toBe('currency_unsupported')
        // Transfer is never "unsupported" by currency — a bank transfer works
        // wherever we hold an account, which is why it's the rail Myanmar
        // shops will ever have.
        ->and($plans['pro']['rail_status']['manual'])->toBe('available')
        ->and($plans['pro']['rails'])->toBe(['manual']);
});

/**
 * The refusal message has to match: telling a Myanmar shop card is
 * unavailable "yet" would have them waiting for something not coming.
 */
test('asking for card in Kyat is refused as permanent, not as pending setup', function () {
    config()->set('payments.stripe.secret', 'sk_test_configured');

    [, $token] = billingShop(['plan' => 'starter'], currency: 'MMK');

    $message = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'pro', 'rail' => 'stripe'])
        ->assertStatus(422)
        ->json('message');

    expect($message)->toContain('not supported in MMK')
        ->and($message)->not->toContain('yet');
});

test('a rail with no receiving account reads as not_configured, not unsupported', function () {
    config()->set('billing.currencies.MMK.manual.account_number', null);

    [, $token] = billingShop(['plan' => 'starter'], currency: 'MMK');

    $plans = collect($this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/billing')->assertOk()->json('data.plans'))->keyBy('code');

    // Ours to fix, and the copy should say so.
    expect($plans['pro']['rail_status']['manual'])->toBe('not_configured');
});
