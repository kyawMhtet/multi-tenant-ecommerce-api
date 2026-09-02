<?php

use App\Models\Tenant;
use App\Models\User;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'shop_name' => 'Aung Shop',
        'slug' => 'aung-shop',
        'owner_name' => 'Aung Aung',
        'owner_email' => 'aung@shop.test',
        'owner_phone' => '09123456789',
        'password' => 'password123',
    ], $overrides);
}

test('registers a new tenant with its owner user', function () {
    $response = $this->postJson('/api/v1/register', registrationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.email', 'aung@shop.test')
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.tenant_slug', 'aung-shop')
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role', 'tenant_id', 'tenant_slug']]);

    $tenant = Tenant::where('slug', 'aung-shop')->firstOrFail();
    $user = User::where('email', 'aung@shop.test')->firstOrFail();

    expect($tenant->name)->toBe('Aung Shop')
        ->and($tenant->is_active)->toBeTrue()
        ->and($user->tenant_id)->toBe($tenant->id)
        ->and($user->role)->toBe('owner');
});

/**
 * Registering is not signing in. The owner types the password they just chose
 * at /login, which proves they know it rather than riding a session they never
 * authenticated for — and keeps login() the only thing that mints tokens.
 */
test('registration does not sign the owner in', function () {
    $response = $this->postJson('/api/v1/register', registrationPayload())->assertCreated();

    expect($response->json('token'))->toBeNull()
        // No token was minted at all, not merely withheld from the response.
        ->and(User::where('email', 'aung@shop.test')->firstOrFail()->tokens()->count())->toBe(0);
});

/**
 * The end-to-end assertion the old token check was really making: signing up
 * produces an account that WORKS, not just database rows. It just goes through
 * the front door now.
 */
test('the new owner can sign in with the password they chose and reach their shop', function () {
    $this->postJson('/api/v1/register', registrationPayload())->assertCreated();

    $token = $this->postJson('/api/v1/login', [
        'email' => 'aung@shop.test',
        'password' => 'password123',
    ])->assertOk()->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.slug', 'aung-shop');
});

test('rejects a duplicate slug', function () {
    makeTenantUser(tenantOverrides: ['slug' => 'taken-shop']);

    $this->postJson('/api/v1/register', registrationPayload(['slug' => 'taken-shop']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('rejects a duplicate email', function () {
    makeTenantUser(userOverrides: ['email' => 'taken@shop.test']);

    $this->postJson('/api/v1/register', registrationPayload(['owner_email' => 'taken@shop.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('owner_email');
});

test('rejects a reserved slug', function () {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => 'admin']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('rejects a slug with invalid characters', function () {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => 'My Shop!']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('rejects a slug with leading or trailing hyphens', function (string $slug) {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => $slug]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with(['-shop', 'shop-', '-shop-', '-']);

test('accepts a slug with interior hyphens', function () {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => 'aung-mini-mart']))
        ->assertCreated();
});

test('rejects www and other infrastructure hostnames as slugs', function (string $slug) {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => $slug]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with(['www', 'mail', 'cdn']);

test('registration ignores a bearer token from an already-logged-in tenant', function () {
    // A logged-in owner of tenant A opening /register would have their
    // token attached by any client that sets Authorization unconditionally.
    // The new tenant must be genuinely new, never attached to tenant A.
    [$tenantA, $userA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
    );
    $tokenA = $userA->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/register', registrationPayload())
        ->assertCreated();

    $newUser = User::where('email', 'aung@shop.test')->firstOrFail();
    $newTenant = Tenant::where('slug', 'aung-shop')->firstOrFail();

    expect($newTenant->id)->not->toBe($tenantA->id)
        ->and($newUser->tenant_id)->toBe($newTenant->id)
        ->and($newUser->id)->not->toBe($userA->id)
        ->and($response->json('data.tenant_slug'))->toBe('aung-shop');
});

test('rejects a password shorter than 8 characters', function () {
    $this->postJson('/api/v1/register', registrationPayload(['password' => 'short']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

test('a failed registration creates neither tenant nor user', function () {
    $this->postJson('/api/v1/register', registrationPayload(['slug' => 'admin']))
        ->assertStatus(422);

    expect(Tenant::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

test('a newly registered tenant starts with no data from any other tenant', function () {
    // An existing tenant with real data — the new signup must not see any
    // of it, proving the fresh tenant is genuinely isolated from the start.
    [$existingTenant, $existingUser] = makeTenantUser(
        userOverrides: ['email' => 'existing@shop.test'],
        tenantOverrides: ['slug' => 'existing-shop', 'owner_email' => 'existing@shop.test'],
    );
    $variant = createProductForTenant($existingTenant)->variants->first();
    createPosOrderForTenant($existingTenant, $existingUser, [
        ['product_variant_id' => $variant->id, 'quantity' => 1],
    ]);

    // Register then sign in — registration issues no token any more.
    $this->postJson('/api/v1/register', registrationPayload())->assertCreated();

    $token = $this->postJson('/api/v1/login', [
        'email' => 'aung@shop.test',
        'password' => 'password123',
    ])->assertOk()->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
