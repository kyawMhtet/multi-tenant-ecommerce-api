<?php

use App\Models\Order;
use App\Models\Tenant;

function register(array $overrides = [])
{
    return test()->postJson('/api/v1/register', array_merge([
        'shop_name' => 'Bangkok Noodles',
        'slug' => 'bangkok-noodles',
        'owner_name' => 'Aung',
        'owner_email' => 'aung@shop.test',
        'owner_phone' => '0812345678',
        'password' => 'password123',
    ], $overrides));
}

test('a shop defaults to MMK and Yangon time, preserving the original signup flow', function () {
    register()->assertCreated();

    $tenant = Tenant::firstOrFail();
    expect($tenant->currency)->toBe('MMK')
        ->and($tenant->timezone)->toBe('Asia/Yangon');
});

/**
 * The gap this closes: a Thai shop signing up used to silently trade in
 * Kyat, because currency was only ever a column default.
 */
test('a Thai shop can sign up in baht and Bangkok time', function () {
    register(['currency' => 'THB', 'timezone' => 'Asia/Bangkok'])->assertCreated();

    $tenant = Tenant::firstOrFail();
    expect($tenant->currency)->toBe('THB')
        ->and($tenant->timezone)->toBe('Asia/Bangkok');
});

test('an unsupported currency is rejected', function () {
    register(['currency' => 'XYZ'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');

    expect(Tenant::count())->toBe(0);
});

test('an invalid timezone is rejected', function () {
    register(['timezone' => 'Middle/Earth'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');
});

/**
 * Money columns carry no currency tag, so changing it once orders exist
 * would retroactively reinterpret every historical total. Permanent by
 * design — the request simply doesn't accept the field.
 */
test('currency cannot be changed after signup', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['currency' => 'USD'])
        ->assertOk();

    expect($tenant->fresh()->currency)->not->toBe('USD');
});

test('timezone can be corrected in settings, unlike currency', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/v1/tenant', ['timezone' => 'Asia/Bangkok'])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Bangkok');

    expect($tenant->fresh()->timezone)->toBe('Asia/Bangkok');
});

test('the storefront is told the shop timezone so it can render hours correctly', function () {
    [$tenant] = makeTenantUser();
    Tenant::whereKey($tenant->id)->update(['timezone' => 'Asia/Bangkok', 'currency' => 'THB']);

    $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->getJson('/api/v1/public/shop')
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Bangkok')
        ->assertJsonPath('data.currency', 'THB');
});

test('the dashboard surfaces money the shop still owes back', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;
    $variant = createProductForTenant($tenant, variantOverrides: ['current_stock' => 10])->variants->first();

    $order = createOnlineOrderForTenant($tenant, [
        ['product_variant_slug' => $variant->slug, 'quantity' => 2],
    ], ['payment_method' => 'qr_transfer']);

    app()->instance('tenant', $tenant);
    $order->update(['payment_status' => 'paid', 'status' => 'cancelled', 'cancelled_at' => now()]);
    app()->forgetInstance('tenant');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.refunds_owed_count', 1)
        // Compared numerically: JSON renders 300.0 as 300, so a strict
        // float assertion would fail on formatting rather than value.
        ->assertJsonPath('data.refunds_owed_total',
            fn ($total) => (float) $total === (float) Order::findOrFail($order->id)->total);
});
