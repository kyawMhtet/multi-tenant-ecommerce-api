<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Ad-hoc routes that just echo back the tenant bound into the
    // container by ResolveTenant, so we can assert on it directly without
    // depending on any real tenant-scoped resource route. One mirrors the
    // authenticated admin routes (['auth:sanctum', 'tenant']), the other
    // the unauthenticated public routes ('tenant' alone) — the middleware
    // now takes a genuinely different path for each.
    Route::middleware(['auth:sanctum', 'tenant'])->get('/api/v1/_test/whoami', function () {
        return response()->json(['tenant_id' => app('tenant')->id]);
    });

    Route::middleware('tenant')->get('/api/v1/_test/public-whoami', function () {
        return response()->json(['tenant_id' => app('tenant')->id]);
    });
});

describe('authenticated requests', function () {
    test('the tenant is resolved from the user, not the header — no header sent at all', function () {
        [$tenant, $user] = makeTenantUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/_test/whoami')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    });

    /**
     * This is the regression test for the original gap: previously,
     * ResolveTenant read X-Tenant-Slug and only checked afterward that it
     * matched the authenticated user's own tenant. Deriving the tenant
     * from the user directly means a foreign (but real, active) tenant's
     * slug in the header has no effect at all — the response must still
     * reflect tenant A's data, never tenant B's.
     */
    test('a different tenant slug in the header is ignored — the user only ever sees their own tenant', function () {
        [$tenantA, $userA] = makeTenantUser(
            userOverrides: ['email' => 'a@shop.test'],
            tenantOverrides: ['slug' => 'tenant-a', 'owner_email' => 'a@shop.test'],
        );

        [$tenantB] = makeTenantUser(
            userOverrides: ['email' => 'b@shop.test'],
            tenantOverrides: ['slug' => 'tenant-b', 'owner_email' => 'b@shop.test'],
        );

        $this->actingAs($userA, 'sanctum')
            ->withHeader('X-Tenant-Slug', $tenantB->slug)
            ->getJson('/api/v1/_test/whoami')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenantA->id]);
    });

    test('a nonexistent tenant slug in the header is also ignored, not rejected', function () {
        [$tenant, $user] = makeTenantUser();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-Slug', 'does-not-exist')
            ->getJson('/api/v1/_test/whoami')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    });

    test('a user with no tenant at all (nullable FK) is rejected regardless of any header value', function () {
        $user = \App\Models\User::factory()->create(['tenant_id' => null]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-Slug', 'some-other-tenant')
            ->getJson('/api/v1/_test/whoami')
            ->assertNotFound();
    });

    test('an inactive tenant is rejected regardless of any header value', function () {
        [, $user] = makeTenantUser(tenantOverrides: ['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/_test/whoami')
            ->assertNotFound();
    });
});

describe('unauthenticated requests', function () {
    test('a request without a X-Tenant-Slug header is rejected with 404', function () {
        $this->getJson('/api/v1/_test/public-whoami')->assertNotFound();
    });

    test('a request for an unknown tenant slug is rejected with 404', function () {
        $this->withHeader('X-Tenant-Slug', 'does-not-exist')
            ->getJson('/api/v1/_test/public-whoami')
            ->assertNotFound();
    });

    test('a request for an inactive tenant is rejected with 404', function () {
        [$tenant] = makeTenantUser(tenantOverrides: ['is_active' => false]);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/v1/_test/public-whoami')
            ->assertNotFound();
    });

    test('a request with a valid, active tenant slug succeeds', function () {
        [$tenant] = makeTenantUser();

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/v1/_test/public-whoami')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    });
});
