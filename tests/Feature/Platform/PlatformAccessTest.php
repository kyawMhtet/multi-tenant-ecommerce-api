<?php

use App\Models\Product;

/**
 * The most important tests in the billing work.
 *
 * A platform admin can read and settle money across every tenant, so the two
 * identities must not be interchangeable at any door. The trap this guards
 * against is specific and quiet: Sanctum's personal access tokens are
 * POLYMORPHIC and its guard authenticates whatever model a token points at
 * without consulting the configured provider — so `auth:sanctum` alone lets
 * either identity through either door. Worse, TenantScope::apply() skips its
 * filter when currentTenantId() is falsy, and a PlatformAdmin has no
 * tenant_id at all, so a platform token reaching a tenant route would run
 * UNSCOPED across every shop.
 *
 * Both doors therefore check by TYPE. These tests are what prove it.
 */

test('a platform admin token cannot reach tenant routes', function () {
    [$tenant] = makeTenantUser();
    createProductForTenant($tenant, ['name' => 'Secret Inventory']);

    $token = makePlatformAdmin()->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertForbidden();
});

/**
 * The specific failure this rules out: not just "denied", but that no
 * unscoped read happened on the way to being denied.
 */
test('a platform admin token never returns another shop data through a tenant route', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
    );

    createProductForTenant($tenantA, ['name' => 'Shop A Product']);
    createProductForTenant($tenantB, ['name' => 'Shop B Product']);

    expect(Product::withoutGlobalScope(App\Models\Concerns\TenantScope::class)->count())->toBe(2);

    $token = makePlatformAdmin()->createToken('t')->plainTextToken;

    // Sending a slug header too — if the type check were removed, this is
    // exactly the request that would resolve a tenant and start working.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/products');

    $response->assertForbidden();
    expect($response->json('data'))->toBeNull();
});

test('a shop owner token cannot reach platform routes', function () {
    [, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $auth = $this->withHeader('Authorization', "Bearer {$token}");

    $auth->getJson('/api/v1/platform/billing/pending')->assertForbidden();
    $auth->getJson('/api/v1/platform/me')->assertForbidden();
    $auth->postJson('/api/v1/platform/billing/invoices/1/approve')->assertForbidden();
});

test('platform routes require authentication at all', function () {
    $this->getJson('/api/v1/platform/billing/pending')->assertUnauthorized();
    $this->getJson('/api/v1/platform/me')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Sign-in
// ---------------------------------------------------------------------------

test('a platform admin can sign in and read their own account', function () {
    makePlatformAdmin(['email' => 'ops@platform.test']);

    $token = $this->postJson('/api/v1/platform/login', [
        'email' => 'ops@platform.test',
        'password' => 'correct-horse-battery',
    ])
        ->assertOk()
        ->assertJsonPath('data.admin.email', 'ops@platform.test')
        ->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/platform/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ops@platform.test');
});

test('bad credentials are refused', function () {
    makePlatformAdmin(['email' => 'ops@platform.test']);

    $this->postJson('/api/v1/platform/login', [
        'email' => 'ops@platform.test',
        'password' => 'wrong',
    ])->assertJsonValidationErrors('email');
});

/**
 * The same generic message as bad credentials. "This account is disabled"
 * confirms the email is real AND that someone thought it worth revoking.
 */
test('a deactivated admin cannot sign in, and is told nothing useful', function () {
    makePlatformAdmin(['email' => 'ex@platform.test', 'is_active' => false]);

    $this->postJson('/api/v1/platform/login', [
        'email' => 'ex@platform.test',
        'password' => 'correct-horse-battery',
    ])
        ->assertJsonValidationErrors('email')
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

/**
 * is_active is checked on every REQUEST, not only at sign-in, so revoking
 * someone takes effect immediately rather than whenever their token happens
 * to expire.
 *
 * Deliberately one request against an already-deactivated admin holding a
 * valid token, rather than "call, deactivate, call again": Sanctum's guard
 * caches the resolved user for the rest of the test process (see the note on
 * createPosOrderForTenant in Pest.php), so the second call would still see
 * the stale in-memory is_active and pass for the wrong reason. Since login is
 * never called here, only the middleware can be what refuses it.
 */
test('a token held by a deactivated admin is refused on every request', function () {
    $admin = makePlatformAdmin();
    $token = $admin->createToken('t')->plainTextToken;

    $admin->forceFill(['is_active' => false])->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/platform/billing/pending')
        ->assertForbidden();
});

/**
 * A platform admin is not a shop owner even when the same person is both.
 * Separate tables, separate unique indexes, separate credentials.
 */
test('the same email can be both a shop owner and platform staff', function () {
    makeTenantUser(userOverrides: ['email' => 'kyaw@example.test']);

    expect(fn () => makePlatformAdmin(['email' => 'kyaw@example.test']))->not->toThrow(Exception::class);
});
