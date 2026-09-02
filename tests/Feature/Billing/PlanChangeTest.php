<?php

use App\Models\Concerns\TenantScope;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\SubscriptionService;

/**
 * Moving between plans mid-period. The cases diverge sharply and each one is
 * a deliberate decision:
 *
 *   renew    — extends from where paid access ends
 *   upgrade  — starts NOW, and applies the moment it's approved
 *   downgrade— starts at period end, and is SCHEDULED rather than applied,
 *              because the shop paid for the higher plan through this period
 *
 * Requests go through SubscriptionService directly and approvals through
 * HTTP, so each test authenticates as exactly one identity — Sanctum caches
 * the resolved user for the whole test process.
 */
beforeEach(function () {
    config()->set('billing.currencies.THB.manual', [
        'bank_name' => 'Bangkok Bank',
        'account_name' => 'Shop SaaS Co Ltd',
        'account_number' => 'TH-1234567890',
        'instructions' => null,
    ]);
    config()->set('billing.currencies.THB.plans.starter.amount', 499.0);
    config()->set('billing.currencies.THB.plans.pro.amount', 699.0);
});

function thbShop(array $subscription): array
{
    $unique = Illuminate\Support\Str::random(6);

    [$tenant] = makeTenantUser(
        userOverrides: ['email' => "owner-{$unique}@shop.test"],
        tenantOverrides: [
            'currency' => 'THB',
            'slug' => "shop-{$unique}",
            'owner_email' => "owner-{$unique}@shop.test",
        ],
    );

    return [$tenant, subscribeTenant($tenant, $subscription)];
}

// ---------------------------------------------------------------------------
// Renewing the same plan
// ---------------------------------------------------------------------------

test('renewing the same plan extends from where paid access ends', function () {
    $endsAt = now()->addDays(10);
    [$tenant] = thbShop(['plan' => 'starter', 'gateway' => 'manual', 'current_period_ends_at' => $endsAt]);

    $invoice = requestTransferForTenant($tenant, 'starter');

    expect($invoice->period_start->toDateString())->toBe($endsAt->toDateString())
        ->and((float) $invoice->amount)->toBe(499.0);

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    expect($tenant->fresh()->subscription->current_period_ends_at->toDateString())
        ->toBe($endsAt->copy()->addMonth()->toDateString());
});

// ---------------------------------------------------------------------------
// Upgrading
// ---------------------------------------------------------------------------

/**
 * An upgrade begins when the shop actually gets the higher plan. An invoice
 * claiming a period that started after access did would be unreconcilable
 * against a bank statement.
 */
test('an upgrade is invoiced from today, not from the end of the cheap period', function () {
    [$tenant] = thbShop(['plan' => 'starter', 'gateway' => 'manual', 'current_period_ends_at' => now()->addDays(20)]);

    $invoice = requestTransferForTenant($tenant, 'pro');

    expect($invoice->period_start->toDateString())->toBe(now()->toDateString())
        ->and($invoice->period_end->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and((float) $invoice->amount)->toBe(699.0);
});

test('an approved upgrade applies immediately', function () {
    [$tenant] = thbShop(['plan' => 'starter', 'gateway' => 'manual', 'current_period_ends_at' => now()->addDays(20)]);

    $invoice = requestTransferForTenant($tenant, 'pro');

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->effectivePlan())->toBe('pro')
        ->and($subscription->pending_plan)->toBeNull()
        ->and($subscription->allows(App\Services\Billing\PlanFeature::ProfitReports))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Downgrading
// ---------------------------------------------------------------------------

/**
 * The shop paid for Pro through the end of this period and keeps it. Taking a
 * paid feature back mid-period is the one thing the rest of this design
 * refuses to do.
 */
test('a downgrade is scheduled, not applied, while paid time remains', function () {
    $endsAt = now()->addDays(14);
    [$tenant] = thbShop(['plan' => 'pro', 'gateway' => 'manual', 'current_period_ends_at' => $endsAt]);

    $invoice = requestTransferForTenant($tenant, 'starter');

    expect($invoice->period_start->toDateString())->toBe($endsAt->toDateString());

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;

    // Still Pro today...
    expect($subscription->effectivePlan())->toBe('pro')
        ->and($subscription->allows(App\Services\Billing\PlanFeature::ProfitReports))->toBeTrue()
        ->and($subscription->hasScheduledPlanChange())->toBeTrue()
        ->and($subscription->pending_plan)->toBe('starter');

    // ...and Starter once the paid period has run out. No scheduler involved:
    // effectivePlan() compares the date.
    $subscription->forceFill(['pending_plan_starts_at' => now()->subMinute()])->save();

    expect($subscription->fresh()->effectivePlan())->toBe('starter')
        ->and($subscription->fresh()->allows(App\Services\Billing\PlanFeature::ProfitReports))->toBeFalse();
});

/**
 * Nothing to protect when the paid period is already over, so there is no
 * reason to make a lapsed shop wait for the cheaper plan it just paid for.
 */
test('a downgrade applies immediately when there is no paid time left', function () {
    [$tenant] = thbShop(['plan' => 'pro', 'gateway' => 'manual', 'current_period_ends_at' => now()->subMonth()]);

    $invoice = requestTransferForTenant($tenant, 'starter');

    actingAsPlatform()->postJson("/api/v1/platform/billing/invoices/{$invoice->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->effectivePlan())->toBe('starter')
        ->and($subscription->pending_plan)->toBeNull();
});

test('upgrading after scheduling a downgrade cancels the downgrade', function () {
    $endsAt = now()->addDays(14);
    [$tenant] = thbShop(['plan' => 'pro', 'gateway' => 'manual', 'current_period_ends_at' => $endsAt]);

    $scheduled = requestTransferForTenant($tenant, 'starter');
    $platform = actingAsPlatform();
    $platform->postJson("/api/v1/platform/billing/invoices/{$scheduled->id}/approve")->assertOk();

    expect($tenant->fresh()->subscription->hasScheduledPlanChange())->toBeTrue();

    $upgrade = requestTransferForTenant($tenant, 'pro');
    $platform->postJson("/api/v1/platform/billing/invoices/{$upgrade->id}/approve")->assertOk();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription->hasScheduledPlanChange())->toBeFalse()
        ->and($subscription->pending_plan)->toBeNull()
        ->and($subscription->effectivePlan())->toBe('pro');
});

// ---------------------------------------------------------------------------
// Two things that used to go wrong
// ---------------------------------------------------------------------------

/**
 * The reuse guard only matches the same plan, so changing your mind before
 * paying would leave two unpaid invoices — one screenshot between them, and
 * approving both would grant two periods.
 */
test('choosing a different plan before paying voids the earlier invoice', function () {
    [$tenant] = thbShop(['plan' => 'starter', 'gateway' => 'manual', 'current_period_ends_at' => now()->addDays(5)]);

    $first = requestTransferForTenant($tenant, 'starter');
    $second = requestTransferForTenant($tenant, 'pro');

    expect($second->id)->not->toBe($first->id);

    $all = SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->get()->keyBy('id');

    expect($all[$first->id]->status)->toBe('void')
        ->and($all[$second->id]->status)->toBe('pending')
        // Only the live one can be paid or reused.
        ->and(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->unpaid()->count())->toBe(1);

    // ...and the review queue shows one item, not two.
    actingAsPlatform()->getJson('/api/v1/platform/billing/pending')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * Approving a transfer flips `gateway` to manual. With a live Stripe
 * subscription still running, that would leave the shop paying twice a month
 * with nothing in the app showing it.
 */
test('a shop with a live card subscription cannot also pay by transfer', function () {
    [$tenant] = thbShop([
        'plan' => 'pro',
        'gateway' => 'stripe',
        'external_subscription_ref' => 'sub_live_123',
        'current_period_ends_at' => now()->addDays(20),
    ]);

    app()->instance('tenant', $tenant);

    try {
        expect(fn () => app(SubscriptionService::class)->subscribe(
            $tenant->subscription()->firstOrFail(), 'starter', 'manual',
        ))->toThrow(App\Exceptions\BillingActionUnavailableException::class);
    } finally {
        app()->forgetInstance('tenant');
    }

    expect(SubscriptionInvoice::withoutGlobalScope(TenantScope::class)->count())->toBe(0);
});

/**
 * Cancelling first is the documented way out, and it costs the shop nothing —
 * they keep access to the end of the period already paid for.
 */
test('cancelling the card subscription reopens the transfer rail', function () {
    [$tenant] = thbShop([
        'plan' => 'pro',
        'gateway' => 'stripe',
        'external_subscription_ref' => 'sub_live_123',
        'status' => 'cancelled',
        'cancel_at_period_end' => true,
        'current_period_ends_at' => now()->addDays(20),
    ]);

    $invoice = requestTransferForTenant($tenant, 'starter');

    expect($invoice)->not->toBeNull()
        ->and($invoice->plan)->toBe('starter');
});
