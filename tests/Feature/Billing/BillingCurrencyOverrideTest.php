<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingCurrency;

/**
 * What a shop SELLS in and what it PAYS US in are two different facts.
 *
 * They were one field, and that was wrong: tenants.currency interprets every
 * order total (which is why it's immutable), while billing currency decides
 * which of the platform's bank accounts a shop transfers to. They correlate
 * for most shops — a Yangon shop sells Kyat and banks in Kyat — but not all: a
 * shop selling to tourists in USD still banks somewhere, and a Myanmar-owned
 * shop in Thailand may sell Baht while wanting to pay from a Kyat account.
 */
beforeEach(function () {
    foreach (['THB' => 'TH-111', 'MMK' => 'MM-222'] as $code => $account) {
        config()->set("billing.currencies.{$code}.manual", [
            'bank_name' => "{$code} Bank",
            'account_name' => 'Shop SaaS',
            'account_number' => $account,
            'instructions' => null,
        ]);
    }
    config()->set('billing.currencies.THB.plans.pro.amount', 699.0);
    config()->set('billing.currencies.MMK.plans.pro.amount', 89000.0);
});

function shopSellingIn(string $currency): App\Models\Tenant
{
    $unique = Illuminate\Support\Str::random(6);

    [$tenant] = makeTenantUser(
        userOverrides: ['email' => "o-{$unique}@shop.test"],
        tenantOverrides: [
            'currency' => $currency,
            'slug' => "shop-{$unique}",
            'owner_email' => "o-{$unique}@shop.test",
        ],
    );

    return $tenant;
}

// ---------------------------------------------------------------------------
// The default: billing follows selling
// ---------------------------------------------------------------------------

test('billing follows the selling currency when no override is set', function () {
    expect(BillingCurrency::for(shopSellingIn('MMK')->subscription))->toBe('MMK')
        ->and(BillingCurrency::for(shopSellingIn('THB')->subscription))->toBe('THB');
});

/**
 * A selling currency we cannot receive falls back rather than leaving the shop
 * unable to pay at all. It is a fallback, not a decision — these shops are
 * exactly the ones most likely to need an override.
 */
test('a currency we cannot receive falls back to the default', function () {
    $tenant = shopSellingIn('USD');

    expect($tenant->currency)->toBe('USD')
        ->and(BillingCurrency::for($tenant->subscription))->toBe('THB');
});

// ---------------------------------------------------------------------------
// The override
// ---------------------------------------------------------------------------

test('platform staff can bill a Kyat-selling shop in Baht', function () {
    $tenant = shopSellingIn('MMK');

    actingAsPlatform()
        ->postJson("/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency", [
            'currency' => 'THB',
        ])
        ->assertOk()
        ->assertJsonPath('data.shop.selling_currency', 'MMK')
        ->assertJsonPath('data.billing_currency', 'THB')
        ->assertJsonPath('data.billing_currency_override', 'THB');

    $subscription = $tenant->fresh()->subscription;

    // The shop still SELLS in Kyat — the override touches only what it pays us.
    expect($tenant->fresh()->currency)->toBe('MMK')
        ->and(BillingCurrency::for($subscription))->toBe('THB');
});

test('the override changes the price quoted and the account to pay into', function () {
    $tenant = shopSellingIn('MMK');

    $before = requestTransferForTenant($tenant, 'pro');
    expect((float) $before->amount)->toBe(89000.0)
        ->and($before->currency)->toBe('MMK');

    actingAsPlatform()
        ->postJson("/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency", [
            'currency' => 'THB',
        ])->assertOk();

    $after = requestTransferForTenant($tenant->fresh(), 'pro');

    expect((float) $after->amount)->toBe(699.0)
        ->and($after->currency)->toBe('THB')
        ->and($after->id)->not->toBe($before->id);
});

/**
 * A pending invoice carries an amount and a set of bank details the shop was
 * told to use. Reinterpreting either would put a figure in front of a reviewer
 * that nobody ever asked the shop to pay.
 */
test('changing the currency voids pending transfers rather than converting them', function () {
    $tenant = shopSellingIn('MMK');
    $stale = requestTransferForTenant($tenant, 'pro');

    actingAsPlatform()
        ->postJson("/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency", [
            'currency' => 'THB',
        ])->assertOk();

    expect(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->find($stale->id)->status)
        ->toBe('void');
});

test('passing null restores the default of following the selling currency', function () {
    $tenant = shopSellingIn('MMK');
    $url = "/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency";
    $platform = actingAsPlatform();

    $platform->postJson($url, ['currency' => 'THB'])->assertOk();
    $platform->postJson($url, ['currency' => null])
        ->assertOk()
        ->assertJsonPath('data.billing_currency_override', null)
        ->assertJsonPath('data.billing_currency', 'MMK');
});

test('a currency the platform cannot receive is rejected', function () {
    $tenant = shopSellingIn('MMK');

    actingAsPlatform()
        ->postJson("/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency", [
            'currency' => 'EUR',
        ])
        ->assertJsonValidationErrors('currency');
});

// ---------------------------------------------------------------------------
// The shop cannot set it
// ---------------------------------------------------------------------------

/**
 * Left to the shop this would be an arbitrage lever, not a preference: Pro is
 * 699 THB against 89,000 MMK (roughly 636 THB), and the gap moves with FX.
 */
test('a shop cannot choose its own billing currency', function () {
    $tenant = shopSellingIn('MMK');
    $token = $tenant->users()->first()->createToken('t')->plainTextToken;
    $auth = $this->withHeader('Authorization', "Bearer {$token}");

    // Not accepted on the subscribe endpoint...
    $auth->postJson('/api/v1/billing/subscribe', [
        'plan' => 'pro', 'rail' => 'manual', 'currency' => 'THB',
    ])->assertOk()->assertJsonPath('data.invoice.currency', 'MMK');

    // ...nor through the shop profile, which already refuses the selling
    // currency for its own (unrelated) reason.
    $auth->patchJson('/api/v1/tenant', ['name' => 'Renamed', 'currency' => 'THB'])->assertOk();

    // ...nor by reaching the staff endpoint directly.
    $auth->postJson("/api/v1/platform/subscriptions/{$tenant->subscription->id}/billing-currency", [
        'currency' => 'THB',
    ])->assertForbidden();

    expect($tenant->fresh()->currency)->toBe('MMK')
        ->and($tenant->fresh()->subscription->billing_currency)->toBeNull();
});
