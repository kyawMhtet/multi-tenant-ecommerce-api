<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;

/**
 * The management layer around the review queue: which shops exist, what a
 * given shop's billing looks like, the full invoice ledger, and staff
 * accounts.
 *
 * Every query here crosses tenants deliberately. Tenant itself carries no
 * global scope, but its relations do, so the service strips TenantScope
 * explicitly on each one — these tests are what prove those loads actually
 * return other tenants' rows rather than silently empty ones.
 */
function seedShops(): array
{
    $shops = [];

    foreach ([
        ['slug' => 'yangon-mart', 'name' => 'Yangon Mart', 'currency' => 'MMK'],
        ['slug' => 'bangkok-bags', 'name' => 'Bangkok Bags', 'currency' => 'THB'],
        ['slug' => 'chiang-tea', 'name' => 'Chiang Tea', 'currency' => 'THB'],
    ] as $i => $shop) {
        [$tenant] = makeTenantUser(
            userOverrides: ['email' => "{$shop['slug']}@shop.test"],
            tenantOverrides: $shop + ['owner_email' => "{$shop['slug']}@shop.test"],
        );
        $shops[$shop['slug']] = $tenant;
    }

    subscribeTenant($shops['yangon-mart'], ['plan' => 'starter', 'status' => 'active', 'gateway' => 'manual']);
    subscribeTenant($shops['bangkok-bags'], ['plan' => 'pro', 'status' => 'past_due', 'gateway' => 'stripe']);
    subscribeTenant($shops['chiang-tea'], ['plan' => 'pro', 'status' => 'active', 'gateway' => 'manual']);

    return $shops;
}

// ---------------------------------------------------------------------------
// Directory
// ---------------------------------------------------------------------------

test('the directory lists every shop with its billing state', function () {
    seedShops();

    $data = actingAsPlatform()->getJson('/api/v1/platform/shops')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->json('data');

    $shops = collect($data)->keyBy('slug');

    expect($shops['bangkok-bags']['subscription']['plan'])->toBe('pro')
        ->and($shops['bangkok-bags']['subscription']['status'])->toBe('past_due')
        ->and($shops['bangkok-bags']['subscription']['rail'])->toBe('stripe')
        // The SELLING currency and the BILLING currency are different facts,
        // and the dashboard shows both.
        ->and($shops['yangon-mart']['selling_currency'])->toBe('MMK')
        ->and($shops['yangon-mart']['subscription']['billing_currency'])->toBe('MMK')
        ->and($shops['yangon-mart']['is_suspended'])->toBeFalse();
});

test('search matches name, slug and owner email', function () {
    seedShops();
    $platform = actingAsPlatform();

    $slugs = fn (string $q) => collect(
        $platform->getJson("/api/v1/platform/shops?search={$q}")->assertOk()->json('data')
    )->pluck('slug')->all();

    expect($slugs('Yangon'))->toBe(['yangon-mart'])
        ->and($slugs('bangkok-bags'))->toBe(['bangkok-bags'])
        ->and($slugs('chiang-tea@shop.test'))->toBe(['chiang-tea']);
});

test('filters narrow by plan, status, rail and currency', function () {
    seedShops();
    $platform = actingAsPlatform();

    $count = fn (string $query) => count(
        $platform->getJson("/api/v1/platform/shops?{$query}")->assertOk()->json('data')
    );

    expect($count('plan=pro'))->toBe(2)
        ->and($count('status=active'))->toBe(2)
        ->and($count('rail=stripe'))->toBe(1)
        ->and($count('currency=THB'))->toBe(2)
        ->and($count('plan=pro&rail=manual'))->toBe(1);
});

/**
 * A typo should be a visible 422, not an empty list the reviewer reads as
 * "there are no such shops".
 */
test('an unknown filter value is rejected rather than silently returning nothing', function () {
    actingAsPlatform()->getJson('/api/v1/platform/shops?plan=enterprise')
        ->assertJsonValidationErrors('plan');
});

test('suspended shops can be listed on their own', function () {
    $shops = seedShops();
    $platform = actingAsPlatform();

    $platform->postJson("/api/v1/platform/shops/{$shops['chiang-tea']->id}/suspend", [
        'reason' => 'Investigating a chargeback.',
    ])->assertOk();

    $suspended = collect($platform->getJson('/api/v1/platform/shops?suspended=1')->json('data'));

    expect($suspended)->toHaveCount(1)
        ->and($suspended->first()['slug'])->toBe('chiang-tea')
        ->and(count($platform->getJson('/api/v1/platform/shops?suspended=0')->json('data')))->toBe(2);
});

// ---------------------------------------------------------------------------
// Detail
// ---------------------------------------------------------------------------

test('shop detail carries the subscription, invoices and usage counts', function () {
    $shops = seedShops();
    $tenant = $shops['chiang-tea'];

    createProductForTenant($tenant);
    createTransferInvoice($tenant, ['plan' => 'pro']);

    actingAsPlatform()->getJson("/api/v1/platform/shops/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('data.slug', 'chiang-tea')
        ->assertJsonPath('data.owner_email', 'chiang-tea@shop.test')
        ->assertJsonPath('data.products_count', 1)
        ->assertJsonPath('data.orders_count', 0)
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.plan', 'pro');
});

test('shop detail shows only that shop invoices', function () {
    $shops = seedShops();

    createTransferInvoice($shops['yangon-mart']);
    createTransferInvoice($shops['chiang-tea']);

    actingAsPlatform()->getJson("/api/v1/platform/shops/{$shops['chiang-tea']->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.shop.slug', 'chiang-tea');
});

test('an unknown shop is a 404', function () {
    actingAsPlatform()->getJson('/api/v1/platform/shops/99999')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Invoice ledger
// ---------------------------------------------------------------------------

test('the ledger spans every shop and every status, unlike the review queue', function () {
    $shops = seedShops();

    createTransferInvoice($shops['yangon-mart'], ['currency' => 'MMK', 'amount' => 89000]);
    createTransferInvoice($shops['chiang-tea'], ['status' => 'paid', 'paid_at' => now()]);
    createTransferInvoice($shops['bangkok-bags'], ['gateway' => 'stripe', 'external_ref' => 'in_1', 'status' => 'paid']);

    $platform = actingAsPlatform();

    // The queue only surfaces unpaid transfers waiting on a human...
    $platform->getJson('/api/v1/platform/billing/pending')->assertOk()->assertJsonCount(1, 'data');

    // ...the ledger is the whole history.
    $platform->getJson('/api/v1/platform/billing/invoices')->assertOk()->assertJsonCount(3, 'data');

    $count = fn (string $q) => count($platform->getJson("/api/v1/platform/billing/invoices?{$q}")->json('data'));

    expect($count('status=paid'))->toBe(2)
        ->and($count('rail=stripe'))->toBe(1)
        ->and($count('currency=MMK'))->toBe(1)
        ->and($count('tenant_id='.$shops['chiang-tea']->id))->toBe(1);
});

test('the ledger filters by date range inclusively', function () {
    $shops = seedShops();
    createTransferInvoice($shops['chiang-tea']);

    $today = now()->toDateString();

    // A reviewer entering a month end means "up to and including" it.
    expect(count(actingAsPlatform()
        ->getJson("/api/v1/platform/billing/invoices?from={$today}&to={$today}")
        ->assertOk()->json('data')))->toBe(1);
});

// ---------------------------------------------------------------------------
// Staff
// ---------------------------------------------------------------------------

test('staff can create and deactivate other admins', function () {
    $platform = actingAsPlatform();

    $created = $platform->postJson('/api/v1/platform/admins', [
        'name' => 'Reviewer',
        'email' => 'reviewer@platform.test',
        'password' => 'a-long-enough-password',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'reviewer@platform.test')
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    // The hash must never appear in a staff listing.
    $platform->getJson('/api/v1/platform/admins')
        ->assertOk()
        ->assertJsonMissingPath('data.0.password');

    $platform->postJson("/api/v1/platform/admins/{$created}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(PlatformAdmin::find($created)->is_active)->toBeFalse();

    $platform->postJson("/api/v1/platform/admins/{$created}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

/**
 * One click that would otherwise lock every human out of the payment queue,
 * with no way back except the artisan command.
 */
test('an admin cannot deactivate themselves', function () {
    $admin = makePlatformAdmin();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/platform/admins/{$admin->id}/deactivate")
        ->assertStatus(422)
        ->assertJsonPath('reason', 'billing_action_unavailable');

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('staff passwords must be at least 12 characters and emails unique', function () {
    makePlatformAdmin(['email' => 'taken@platform.test']);
    $platform = actingAsPlatform();

    $platform->postJson('/api/v1/platform/admins', [
        'name' => 'Short', 'email' => 'new@platform.test', 'password' => 'short',
    ])->assertJsonValidationErrors('password');

    $platform->postJson('/api/v1/platform/admins', [
        'name' => 'Dup', 'email' => 'taken@platform.test', 'password' => 'a-long-enough-password',
    ])->assertJsonValidationErrors('email');
});

/**
 * The same person may legitimately run a shop AND be platform staff. Separate
 * tables, separate unique indexes.
 */
test('a platform admin email may match a shop owner email', function () {
    makeTenantUser(userOverrides: ['email' => 'both@example.test']);

    actingAsPlatform()->postJson('/api/v1/platform/admins', [
        'name' => 'Both', 'email' => 'both@example.test', 'password' => 'a-long-enough-password',
    ])->assertCreated();

    expect(Tenant::count())->toBe(1);
});
