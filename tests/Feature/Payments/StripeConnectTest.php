<?php

use App\Models\Tenant;

/**
 * These cover the paths that don't call Stripe. The onboarding-link route
 * genuinely does hit Stripe's API, so it isn't exercised here — mocking
 * StripeClient deeply enough to be meaningful would end up asserting
 * against a fake rather than the behaviour that matters. What IS worth
 * pinning down is the disconnected shape and, above all, that neither
 * route accepts a tenant identifier.
 */
test('status reports a disconnected shop without calling stripe', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    expect($tenant->stripe_account_id)->toBeNull();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/payments/stripe/status')
        ->assertOk()
        ->assertJsonPath('data.connected', false)
        ->assertJsonPath('data.charges_enabled', false)
        ->assertJsonPath('data.details_submitted', false)
        ->assertJsonPath('data.payouts_enabled', false);
});

test('connect status requires authentication', function () {
    $this->getJson('/api/v1/payments/stripe/status')->assertUnauthorized();
    $this->postJson('/api/v1/payments/stripe/onboarding-link')->assertUnauthorized();
});

/**
 * The isolation guarantee for these endpoints is structural rather than
 * scope-based: neither accepts a tenant id in the URL or body, so they act
 * on app('tenant'), which ResolveTenant derives from the token's owner and
 * never from the X-Tenant-Slug header on an authenticated request. Sending
 * another shop's slug must therefore be inert.
 */
test('an X-Tenant-Slug header naming another shop cannot inspect its stripe account', function () {
    [, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    // Shop B is connected; shop A is not. If the header were honoured,
    // A would see B's connected state.
    Tenant::whereKey($tenantB->id)->update(['stripe_account_id' => 'acct_tenantB']);

    $tokenA = $userA->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->withHeader('X-Tenant-Slug', $tenantB->slug)
        ->getJson('/api/v1/payments/stripe/status')
        ->assertOk()
        ->assertJsonPath('data.connected', false);
});

/**
 * stripe_account_id is what routes real money. It must never be settable
 * through the shop-profile form, or a shop could redirect another shop's
 * takings to itself.
 */
test('stripe_account_id cannot be set through the shop profile endpoint', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', [
            'name' => 'Renamed Shop',
            'stripe_account_id' => 'acct_attacker_controlled',
        ])
        ->assertOk();

    expect($tenant->fresh()->stripe_account_id)->toBeNull()
        ->and($tenant->fresh()->name)->toBe('Renamed Shop');
});
