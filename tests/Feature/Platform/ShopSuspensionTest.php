<?php

use App\Services\Platform\PlatformShopService;

/**
 * Suspension locks the OWNER out. It does not take the shop's storefront down.
 *
 * That asymmetry is the entire reason this exists separately from `is_active`,
 * which 404s both branches of ResolveTenant and would strand the shop's
 * customers mid-order. The second half of these tests is the important half:
 * if the storefront ever starts failing here, the feature has quietly become
 * the thing it was designed not to be.
 *
 * Suspension is applied through the service rather than an HTTP call wherever
 * the test then acts as the SHOP owner — Sanctum caches the resolved user for
 * the whole test process, so two identities in one test both resolve as
 * whichever came first.
 */
function suspendShop(App\Models\Tenant $tenant, string $reason = 'Chargeback under investigation.'): void
{
    app(PlatformShopService::class)->suspend($tenant->id, $reason);
}

test('a suspended shop owner is locked out of their admin', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    suspendShop($tenant);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertForbidden()
        ->assertJsonPath('reason', 'shop_suspended')
        ->assertJsonPath('detail', 'Chargeback under investigation.');
});

test('every shop route is closed, reads included', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    suspendShop($tenant);
    $auth = $this->withHeader('Authorization', "Bearer {$token}");

    foreach (['/api/v1/tenant', '/api/v1/orders', '/api/v1/dashboard/summary', '/api/v1/billing'] as $path) {
        $auth->getJson($path)->assertForbidden();
    }
});

/**
 * THE test. Customers did nothing wrong and are holding links that must keep
 * working.
 */
test('the storefront keeps serving a suspended shop', function () {
    [$tenant] = makeTenantUser();
    $product = createProductForTenant($tenant, ['name' => 'Still For Sale'], ['current_stock' => 10]);
    enablePaymentMethodForTenant($tenant);

    suspendShop($tenant);

    $storefront = $this->withHeader('X-Tenant-Slug', $tenant->slug);

    $storefront->getJson('/api/v1/public/shop')->assertOk();
    $storefront->getJson('/api/v1/public/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Still For Sale');

    // ...and a customer can still complete a purchase.
    $storefront->postJson('/api/v1/public/orders', [
        'items' => [['product_variant_slug' => $product->variants->first()->slug, 'quantity' => 1]],
        'customer_name' => 'Walk-in Customer',
        'customer_phone' => '09123456789',
        'payment_method' => 'cod',
        'fulfillment_type' => 'pickup',
    ])->assertCreated();
});

/**
 * The public product link resolves its tenant from the variant slug alone and
 * never runs ResolveTenant, so it was never at risk — worth pinning anyway,
 * since it's the link customers actually hold.
 */
test('a shared product link still resolves for a suspended shop', function () {
    [$tenant] = makeTenantUser();
    $slug = createProductForTenant($tenant)->variants->first()->slug;

    suspendShop($tenant);

    $this->getJson("/api/v1/public/products/{$slug}")->assertOk();
});

// ---------------------------------------------------------------------------
// The platform endpoints
// ---------------------------------------------------------------------------

test('staff can suspend and restore a shop', function () {
    [$tenant] = makeTenantUser();
    $platform = actingAsPlatform();

    $platform->postJson("/api/v1/platform/shops/{$tenant->id}/suspend", [
        'reason' => 'Payment dispute raised by the cardholder.',
    ])
        ->assertOk()
        ->assertJsonPath('data.is_suspended', true)
        ->assertJsonPath('data.suspension_reason', 'Payment dispute raised by the cardholder.');

    expect($tenant->fresh()->isSuspended())->toBeTrue();

    $platform->postJson("/api/v1/platform/shops/{$tenant->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.is_suspended', false)
        // Cleared with the suspension: leaving it would have the record
        // permanently asserting something no longer true.
        ->assertJsonPath('data.suspension_reason', null);

    expect($tenant->fresh()->isSuspended())->toBeFalse();
});

test('restoring gives the owner their shop back', function () {
    [$tenant, $user] = makeTenantUser();
    $token = $user->createToken('t')->plainTextToken;

    suspendShop($tenant);
    app(PlatformShopService::class)->restore($tenant->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/products')
        ->assertOk();
});

/**
 * Same rule as rejecting a transfer: a shop told only "suspended" can do
 * nothing but open a support ticket.
 */
test('suspending requires a reason', function () {
    [$tenant] = makeTenantUser();

    actingAsPlatform()->postJson("/api/v1/platform/shops/{$tenant->id}/suspend", [])
        ->assertJsonValidationErrors('reason');

    expect($tenant->fresh()->isSuspended())->toBeFalse();
});

/**
 * Correcting or expanding the note is normal; "how long has this been going
 * on" should keep its answer.
 */
test('re-suspending updates the reason but keeps the original timestamp', function () {
    [$tenant] = makeTenantUser();

    suspendShop($tenant, 'Initial note.');
    $firstSuspendedAt = $tenant->fresh()->suspended_at;

    suspendShop($tenant, 'Updated after speaking to the owner.');

    expect($tenant->fresh()->suspension_reason)->toBe('Updated after speaking to the owner.')
        ->and($tenant->fresh()->suspended_at->toDateTimeString())
        ->toBe($firstSuspendedAt->toDateTimeString());
});
