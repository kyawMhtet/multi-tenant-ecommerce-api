<?php

use App\Models\Concerns\TenantScope;
use App\Models\Subscription;

/**
 * Covers the migration that gives pre-billing tenants a subscription.
 *
 * The migration is invoked directly rather than through the migrator, because
 * RefreshDatabase has already run it against an empty database — the state
 * worth testing (a tenant that exists with no subscription) can only be built
 * afterwards.
 */
function runBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_09_01_120000_backfill_subscriptions_for_existing_tenants.php'
    );

    $migration->up();
}

/**
 * The reason this migration exists. PlanGate fails CLOSED on a missing
 * subscription — deliberately, since that state cannot come from registration
 * and treating it as unlimited would make "delete your subscription row" the
 * cheapest upgrade available. The cost is that tenants predating billing would
 * be locked out of their own catalogue the moment it deployed.
 *
 * Split across two tests rather than asserting before-and-after in one,
 * because Sanctum caches the resolved user for the whole test process and the
 * cached User carries an already-loaded tenant->subscription relation. A
 * second request in the same test would answer from that stale relation
 * regardless of what the backfill did. Real requests build fresh models; see
 * the note on createPosOrderForTenant in Pest.php.
 */
test('a tenant with no subscription is locked out of its own catalogue', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [
            'name' => 'Before backfill',
            'variant' => ['sku' => 'BF-1', 'selling_price' => 1000, 'buying_price' => 500],
        ])
        ->assertStatus(402)
        ->assertJsonPath('reason', 'subscription_inactive');
});

test('the backfill gives it a fresh trial and restores access', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    Subscription::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->delete();

    runBackfill();

    $subscription = $tenant->fresh()->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('trialing')
        ->and($subscription->plan)->toBe(config('billing.trial_plan'))
        // A FRESH trial, not an expired one: these shops did nothing wrong,
        // and starting them in read-only would be taking something away.
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue()
        // No rail chosen — a trial has no payment method yet.
        ->and($subscription->gateway)->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [
            'name' => 'After backfill',
            'variant' => ['sku' => 'BF-2', 'selling_price' => 1000, 'buying_price' => 500],
        ])
        ->assertCreated();
});

test('the backfill leaves existing subscriptions alone and can be re-run', function () {
    [$tenant] = makeTenantUser();

    subscribeTenant($tenant, ['plan' => 'starter', 'status' => 'active']);

    runBackfill();
    runBackfill();

    expect(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(1)
        ->and($tenant->fresh()->subscription->plan)->toBe('starter')
        ->and($tenant->fresh()->subscription->status)->toBe('active');
});

test('the backfill covers every tenant that is missing one', function () {
    foreach (['a', 'b', 'c'] as $slug) {
        [$tenant] = makeTenantUser(
            userOverrides: ['email' => "{$slug}@shop.test"],
            tenantOverrides: ['slug' => "shop-{$slug}", 'owner_email' => "{$slug}@shop.test"],
        );

        Subscription::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->delete();
    }

    expect(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(0);

    runBackfill();

    expect(Subscription::withoutGlobalScope(TenantScope::class)->count())->toBe(3);
});
