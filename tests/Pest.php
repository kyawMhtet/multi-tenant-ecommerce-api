<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Creates a tenant and a user belonging to it.
 *
 * @return array{0: \App\Models\Tenant, 1: \App\Models\User}
 */
function makeTenantUser(array $userOverrides = [], array $tenantOverrides = []): array
{
    $tenant = \App\Models\Tenant::create(array_merge([
        'name' => 'Test Shop',
        'slug' => 'test-shop',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@shop.test',
        'owner_phone' => '09123456789',
        'is_active' => true,
    ], $tenantOverrides));

    $user = \App\Models\User::create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Owner',
        'email' => 'owner@shop.test',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'role' => 'owner',
    ], $userOverrides));

    return [$tenant, $user];
}

/**
 * Creates a product (with one variant) belonging to the given tenant.
 *
 * tenant_id is deliberately excluded from Product/ProductVariant's
 * $fillable (see CLAUDE.md: never take tenant_id from input), so it can't
 * be set via a plain ::create([...]) array. Instead we bind 'tenant' into
 * the container the same way ResolveTenant middleware does, and let
 * BelongsToTenant's creating hook auto-fill it — this is the real
 * mechanism, not a workaround for the test.
 */
function createProductForTenant(\App\Models\Tenant $tenant, array $productOverrides = [], array $variantOverrides = []): \App\Models\Product
{
    app()->instance('tenant', $tenant);

    $product = \App\Models\Product::create(array_merge([
        'name' => 'Tenant Product',
        'is_active' => true,
    ], $productOverrides));

    $variant = \App\Models\ProductVariant::create(array_merge([
        'product_id' => $product->id,
        'sku' => 'SKU-'.$product->id,
        'slug' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'buying_price' => 100,
        'selling_price' => 150,
        'track_stock' => true,
        'current_stock' => 10,
    ], $variantOverrides));

    // Don't leave 'tenant' bound past fixture setup — the real
    // ResolveTenant middleware rebinds it per-request anyway, but leaving
    // a stale binding here would let a later HTTP call resolve
    // route-model bindings against the wrong tenant before its own
    // middleware runs.
    app()->forgetInstance('tenant');

    // setRelation(), not ->load('variants'): loading would re-run a
    // tenant-scoped query, and if an earlier authenticated HTTP call in
    // the same test already resolved a *different* tenant's user, Sanctum's
    // guard caches that user for the rest of the test process (unlike real
    // per-request PHP-FPM) — TenantScope's auth()->user()?->tenant_id
    // fallback would then silently filter this product's own
    // just-created variant out of its own relation. Attaching the
    // already-known variant directly sidesteps that fallback entirely
    // rather than depending on it staying a no-op.
    return $product->setRelation('variants', collect([$variant]));
}

/**
 * Creates a category belonging to the given tenant, the same
 * bind-tenant-then-forget pattern as createProductForTenant() (Category's
 * tenant_id is likewise excluded from $fillable, filled by
 * BelongsToTenant's creating hook instead).
 */
function createCategoryForTenant(\App\Models\Tenant $tenant, array $overrides = []): \App\Models\Category
{
    app()->instance('tenant', $tenant);

    $category = \App\Models\Category::create(array_merge([
        'name' => 'Category',
        'slug' => 'category-'.\Illuminate\Support\Str::random(6),
    ], $overrides));

    app()->forgetInstance('tenant');

    return $category;
}

/**
 * Creates a POS order for the given tenant/user directly through
 * OrderService, not an authenticated HTTP call. Laravel's AuthManager
 * caches guard instances per name ($this->guards[$name] ??= ...), and
 * Sanctum's RequestGuard caches the resolved user after first access;
 * Pest's HTTP testing reuses the same container across multiple
 * postJson()/getJson() calls within one test, so a second authenticated
 * call using a *different* user's Bearer token in the same test would
 * silently still resolve as the first user. Use this for any "noise"
 * order that needs to exist for a DIFFERENT tenant than the one the test
 * actually authenticates as via HTTP.
 */
function createPosOrderForTenant(\App\Models\Tenant $tenant, \App\Models\User $user, array $items): \App\Models\Order
{
    app()->instance('tenant', $tenant);
    auth()->login($user);

    $order = app(\App\Services\OrderService::class)->createPosOrder(['items' => $items]);

    auth()->logout();
    app()->forgetInstance('tenant');

    return $order;
}

/**
 * Same purpose as createPosOrderForTenant(), for the public/unauthenticated
 * checkout flow — no auth()->login() needed since createOnlineOrder() never
 * reads the current user, but 'tenant' still needs binding for the
 * tenant-scoped variant lookup and BelongsToTenant's auto-fill on the
 * Order/Customer rows it creates.
 */
function createOnlineOrderForTenant(\App\Models\Tenant $tenant, array $items, array $customerOverrides = []): \App\Models\Order
{
    app()->instance('tenant', $tenant);

    $order = app(\App\Services\OrderService::class)->createOnlineOrder(array_merge([
        'items' => $items,
        'customer_name' => 'Guest Customer',
        'customer_phone' => '09' . random_int(100000000, 999999999),
    ], $customerOverrides));

    app()->forgetInstance('tenant');

    return $order;
}

/**
 * Enables a payment method for a tenant, same bind-tenant-then-forget
 * pattern as the other fixture helpers (TenantPaymentMethod's tenant_id is
 * likewise filled by BelongsToTenant's creating hook, not mass-assigned).
 *
 * Defaults to cash on delivery because it needs no gateway, no credentials
 * and no network: any test that just needs *a* valid payment method should
 * use this rather than pulling Stripe into its setup.
 */
function enablePaymentMethodForTenant(\App\Models\Tenant $tenant, array $overrides = []): \App\Models\TenantPaymentMethod
{
    app()->instance('tenant', $tenant);

    $method = \App\Models\TenantPaymentMethod::create(array_merge([
        'method' => 'cod',
        'gateway' => null,
        'is_enabled' => true,
        'sort_order' => 0,
    ], $overrides));

    app()->forgetInstance('tenant');

    return $method;
}
