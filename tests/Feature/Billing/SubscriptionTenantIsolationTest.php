<?php

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

/**
 * Required by CLAUDE.md for every tenant-scoped resource, and worth more
 * than usual here: these rows decide what a shop is allowed to do. A leak
 * across tenants would not just expose data, it would let one shop read —
 * and, without the scope, potentially write — another shop's entitlements.
 */

test('a shop only ever sees its own subscription', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    subscribeTenant($tenantA, ['plan' => 'starter']);
    subscribeTenant($tenantB, ['plan' => 'pro']);

    app()->instance('tenant', $tenantA);

    try {
        expect(Subscription::count())->toBe(1)
            ->and(Subscription::first()->tenant_id)->toBe($tenantA->id)
            ->and(Subscription::first()->plan)->toBe('starter')
            // The scope is what makes this null rather than tenant B's row.
            ->and(Subscription::whereKey($tenantB->subscription->id)->first())->toBeNull();
    } finally {
        app()->forgetInstance('tenant');
    }
});

test('a shop only ever sees its own subscription invoices', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    foreach ([$tenantA, $tenantB] as $tenant) {
        $tenant->subscriptionInvoices()->create([
            'subscription_id' => $tenant->subscription->id,
            'plan' => 'pro',
            'amount' => 750,
            'currency' => 'THB',
            'gateway' => 'manual',
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => 'pending',
        ]);
    }

    app()->instance('tenant', $tenantB);

    try {
        expect(SubscriptionInvoice::count())->toBe(1)
            ->and(SubscriptionInvoice::first()->tenant_id)->toBe($tenantB->id);
    } finally {
        app()->forgetInstance('tenant');
    }
});

/**
 * The unique index is the constraint that keeps "which plan is this shop on"
 * a question with exactly one answer. Without it, a retried checkout or a
 * redelivered webhook could leave two rows disagreeing, and the shop's
 * abilities would depend on which one loaded first.
 */
test('a tenant cannot end up with two subscriptions', function () {
    [$tenant] = makeTenantUser();

    expect(fn () => $tenant->subscription()->create([
        'plan' => 'pro',
        'status' => 'active',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
