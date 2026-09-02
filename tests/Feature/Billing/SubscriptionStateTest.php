<?php

use App\Models\Subscription;
use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanFeature;

/**
 * These cover the entitlement RULES, not any payment. Nothing here talks to
 * Stripe: what matters first is that "may this shop write" and "does this
 * shop have this feature" are answered correctly from dates and a plan
 * string, because every gate added later trusts these two questions.
 */

test('registration starts a trial on the top plan with a real end date', function () {
    $this->postJson('/api/v1/register', [
        'shop_name' => 'New Shop',
        'slug' => 'new-shop',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@new.test',
        'owner_phone' => '09123456789',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();

    $subscription = \App\Models\Tenant::where('slug', 'new-shop')->first()->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('trialing')
        ->and($subscription->plan)->toBe(config('billing.trial_plan'))
        ->and($subscription->gateway)->toBeNull()
        ->and($subscription->allowsWrites())->toBeTrue();

    // The bug this replaces: no end date meant an unlimited free trial.
    expect($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});

test('a null end date reads as expired, never as eternal', function () {
    $subscription = new Subscription(['plan' => 'pro', 'status' => 'trialing', 'trial_ends_at' => null]);

    expect($subscription->allowsWrites())->toBeFalse()
        ->and($subscription->isReadOnly())->toBeTrue();
});

test('an expired trial keeps writing until grace runs out, then stops', function () {
    $subscription = new Subscription([
        'plan' => 'pro',
        'status' => 'trialing',
        'gateway' => null,
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($subscription->allowsWrites())->toBeTrue()
        ->and($subscription->isInGrace())->toBeTrue()
        ->and($subscription->isOnTrial())->toBeFalse();

    $subscription->trial_ends_at = now()->subDays(config('billing.grace_days') + 1);

    expect($subscription->allowsWrites())->toBeFalse()
        ->and($subscription->isInGrace())->toBeFalse();
});

/**
 * A bank transfer has to be sent, clear, and then be checked by a human
 * here. A card either works or it doesn't.
 */
test('the manual rail gets a longer grace window than a card', function () {
    $daysPast = config('billing.grace_days') + 1;

    $card = new Subscription([
        'plan' => 'pro', 'status' => 'past_due', 'gateway' => 'stripe',
        'current_period_ends_at' => now()->subDays($daysPast),
    ]);
    $transfer = new Subscription([
        'plan' => 'pro', 'status' => 'past_due', 'gateway' => 'manual',
        'current_period_ends_at' => now()->subDays($daysPast),
    ]);

    expect($card->allowsWrites())->toBeFalse()
        ->and($transfer->allowsWrites())->toBeTrue();
});

/**
 * Grace absorbs payment friction. Someone who chose to leave has none to
 * absorb, and extending them would just be free service.
 */
test('a deliberate cancellation gets no grace but keeps the period it paid for', function () {
    $subscription = new Subscription([
        'plan' => 'pro', 'status' => 'cancelled', 'gateway' => 'stripe',
        'cancel_at_period_end' => true,
        'current_period_ends_at' => now()->addDays(3),
    ]);

    expect($subscription->allowsWrites())->toBeTrue();

    $subscription->current_period_ends_at = now()->subMinute();

    expect($subscription->allowsWrites())->toBeFalse();
});

/**
 * The two axes are orthogonal, and this is the design decision most likely
 * to be "simplified" later. A lapsed Pro shop is a Pro shop that cannot
 * write — NOT a Starter shop.
 */
test('lapsing makes a shop read-only without silently downgrading its plan', function () {
    $subscription = new Subscription([
        'plan' => 'pro', 'status' => 'past_due', 'gateway' => 'stripe',
        'current_period_ends_at' => now()->subYear(),
    ]);

    expect($subscription->isReadOnly())->toBeTrue()
        ->and($subscription->effectivePlan())->toBe('pro')
        ->and($subscription->allows(PlanFeature::ProfitReports))->toBeTrue();
});

test('a plan that has left the catalogue falls back to the cheapest, never the most generous', function () {
    $subscription = new Subscription([
        'plan' => 'enterprise-that-no-longer-exists',
        'status' => 'active',
        'current_period_ends_at' => now()->addMonth(),
    ]);

    expect($subscription->effectivePlan())->toBe(PlanCatalog::FALLBACK)
        ->and($subscription->allows(PlanFeature::ProfitReports))->toBeFalse()
        ->and($subscription->limitFor('products'))->toBe(50);
});

test('plans gate features and limits as the catalogue describes', function () {
    expect(PlanCatalog::allows('starter', PlanFeature::CardPayments))->toBeFalse()
        ->and(PlanCatalog::allows('pro', PlanFeature::CardPayments))->toBeTrue()
        ->and(PlanCatalog::limitFor('starter', 'staff'))->toBe(3)
        // null is unlimited, and must stay distinguishable from 0.
        ->and(PlanCatalog::limitFor('pro', 'staff'))->toBeNull();
});

/**
 * A shop is billed in its OWN currency, into an account in its own country.
 * This started as one platform-wide currency and that was wrong: a shop
 * inside Myanmar cannot easily wire Baht to a Thai bank, so a Baht-only bill
 * would have broken the manual rail for exactly the shops it exists for.
 */
test('a shop is billed in its own currency, with a fallback for ones we cannot receive', function () {
    $shop = function (string $currency) {
        [$tenant] = makeTenantUser(
            userOverrides: ['email' => strtolower($currency).'@shop.test'],
            tenantOverrides: [
                'currency' => $currency,
                'slug' => 'shop-'.strtolower($currency),
                'owner_email' => strtolower($currency).'@shop.test',
            ],
        );

        return $tenant;
    };

    expect(BillingCurrency::for($shop('MMK')))->toBe('MMK')
        ->and(BillingCurrency::for($shop('THB')))->toBe('THB')
        // USD is a currency a shop may SELL in, but there is no account to
        // receive it — they pay in the default rather than being unable to.
        ->and(BillingCurrency::for($shop('USD')))->toBe('THB')
        ->and(BillingCurrency::for(null))->toBe('THB');
});

/**
 * Every price and rail is answered in the shop's currency, so a Myanmar shop
 * is never quoted a Baht figure it will then be asked to transfer in Kyat.
 */
test('prices and card availability are per currency', function () {
    // Explicit, not config defaults. .env.testing pins these as well, but
    // repeating them here means the numbers asserted below are visible in the
    // test rather than inherited from an env file.
    config()->set('billing.currencies.THB.plans.pro.amount', 750.0);
    config()->set('billing.currencies.MMK.plans.pro.amount', 75000.0);

    expect(BillingCurrency::amountFor('THB', 'pro'))->toBe(750.0)
        ->and(BillingCurrency::amountFor('MMK', 'pro'))->toBe(75000.0)
        // Stripe does not support MMK at all — structural, not unconfigured.
        ->and(BillingCurrency::stripePriceFor('MMK', 'pro'))->toBeNull();
});
