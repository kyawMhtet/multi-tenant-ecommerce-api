<?php

use App\Models\Tenant;

function seedShopProfile(Tenant $tenant): void
{
    $tenant->update([
        'address' => 'No. 9, Bogyoke Road, Yangon',
        'business_phone' => '09111222333',
        'business_email' => 'hello@shop.test',
        'settings' => [
            'business_hours' => ['mon' => [['open' => '09:00', 'close' => '18:00']], 'sun' => []],
            'social_links' => ['facebook' => 'https://facebook.com/myshop'],
        ],
    ]);
}

test('returns the shop profile with no authentication', function () {
    [$tenant] = makeTenantUser();
    seedShopProfile($tenant);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.name', $tenant->name)
        ->assertJsonPath('data.slug', $tenant->slug)
        ->assertJsonPath('data.address', 'No. 9, Bogyoke Road, Yangon')
        ->assertJsonPath('data.business_phone', '09111222333')
        ->assertJsonPath('data.business_hours.mon.0.open', '09:00')
        ->assertJsonPath('data.social_links.facebook', 'https://facebook.com/myshop');
});

/**
 * Mirrors the cost-leak assertion on the public order response: the public
 * shop payload must contain nothing internal, checked both structurally and
 * by scanning the raw body for the owner's account email.
 */
test('never exposes internal or account fields', function () {
    [$tenant] = makeTenantUser();
    seedShopProfile($tenant);

    $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('id')
        ->and($data)->not->toHaveKey('plan')
        ->and($data)->not->toHaveKey('subscription_status')
        ->and($data)->not->toHaveKey('is_active')
        ->and($data)->not->toHaveKey('owner_email')
        ->and($data)->not->toHaveKey('owner_phone')
        ->and(json_encode($response->json()))->not->toContain($tenant->owner_email);
});

/**
 * business_phone deliberately does NOT fall back to owner_phone the way the
 * admin-only TenantResource does — a number given for an account must not be
 * auto-published just because the owner never filled in the shop form.
 */
test('does not fall back to the owner phone when no business phone is set', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['owner_phone' => '09999888777']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.business_phone', null);
});

test('requires a tenant slug header', function () {
    [$tenant] = makeTenantUser();

    $this->getJson('/api/v1/public/shop')->assertNotFound();
});

test('an unknown slug is not found', function () {
    makeTenantUser();

    $this->withHeader('X-Tenant-Slug', 'no-such-shop')
        ->getJson('/api/v1/public/shop')
        ->assertNotFound();
});

test('an inactive tenant storefront does not resolve', function () {
    [$tenant] = makeTenantUser(tenantOverrides: ['is_active' => false]);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertNotFound();
});

/**
 * A pasted product link can't send X-Tenant-Slug, so the product page can't
 * call /public/shop for its header — the same payload is embedded instead.
 */
test('the public product endpoint embeds the same shop payload', function () {
    [$tenant] = makeTenantUser();
    seedShopProfile($tenant);
    $variant = createProductForTenant($tenant)->variants->first();

    $this->getJson("/api/v1/public/products/{$variant->slug}")
        ->assertOk()
        ->assertJsonPath('data.shop.name', $tenant->name)
        ->assertJsonPath('data.shop.business_phone', '09111222333')
        ->assertJsonPath('data.shop.social_links.facebook', 'https://facebook.com/myshop');
});

test('tenant A slug returns tenant A profile, never tenant B', function () {
    [$tenantA] = makeTenantUser(
        userOverrides: ['email' => 'a@shop.test'],
        tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test', 'name' => 'Shop A'],
    );
    [$tenantB] = makeTenantUser(
        userOverrides: ['email' => 'b@shop.test'],
        tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test', 'name' => 'Shop B'],
    );
    seedShopProfile($tenantB);

    $this->withHeader('X-Tenant-Slug', $tenantA->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.name', 'Shop A')
        ->assertJsonPath('data.address', null);
});
