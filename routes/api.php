<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingWebhookController;
use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\Platform\SubscriptionReviewController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryProviderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\PublicPaymentMethodController;
use App\Http\Controllers\Api\PublicProductController;
use App\Http\Controllers\Api\PublicShopController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // No tenant context: the client is proving who it is, not asking for
    // tenant-scoped data.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Outside both auth and 'tenant' by necessity: the tenant is what this
    // creates.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

    // Deliberately NOT behind 'tenant': that middleware reads an
    // X-Tenant-Slug header, which a plain clicked link can't send. The slug
    // identifies both the variant and its tenant.
    Route::get('/public/products/{slug}', [PublicProductController::class, 'show']);

    // Unlike the product page above, these are JS-driven requests from a
    // storefront that knows its own tenant, so they can send X-Tenant-Slug.
    // With no user, ResolveTenant's cross-check is a no-op — the ambient
    // scope it binds is the WHOLE tenant-isolation guarantee for the write
    // path here.
    Route::middleware('tenant')->group(function () {
        Route::post('/public/orders', [PublicOrderController::class, 'store'])
            ->middleware('throttle:public-orders');

        // One throttle for every storefront read: all are hit on each page
        // load, and carriers here put huge numbers of users behind few egress
        // IPs, so the general per-IP limit would 429 real customers.
        Route::middleware('throttle:public-shop')->group(function () {
            Route::get('/public/shop', [PublicShopController::class, 'show']);
            Route::get('/public/products', [PublicProductController::class, 'index']);
            // Same method as the admin route: the query has no sensitive data
            // and no logic beyond the ambient scope, so nothing to duplicate.
            Route::get('/public/categories', [CategoryController::class, 'index']);
            Route::get('/public/payment-methods', [PublicPaymentMethodController::class, 'index']);
        });
    });

    // No auth and no tenant middleware by necessity — server-to-server calls
    // carry neither. The provider's signature IS the authentication, verified
    // in parseWebhook(). This is the ONLY path allowed to mark an order paid:
    // a browser redirect can be faked, lost or closed.
    Route::post('/webhooks/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:payment-webhooks')
        ->where('gateway', 'stripe');

    // Subscription callbacks — money flowing shop -> PLATFORM, the opposite
    // direction to the route above. A separate endpoint on purpose, because
    // Stripe issues a different signing secret per registered endpoint: one
    // shared route would accept either kind of traffic on either secret, and
    // a subscription event would go hunting for an order that doesn't exist.
    // Declared BEFORE nothing in particular, but note it must not be folded
    // into /webhooks/{gateway} even though the path looks similar.
    Route::post('/webhooks/billing/{rail}', [BillingWebhookController::class, 'handle'])
        ->middleware('throttle:payment-webhooks')
        ->where('rail', 'stripe');

    // PLATFORM staff, not shop staff. A completely separate identity: these
    // tokens belong to PlatformAdmin rows, which have no tenant_id and no
    // presence in `users` at all.
    //
    // Every route in here deliberately reads across ALL tenants, so both
    // doors are guarded by TYPE rather than by configuration: 'platform'
    // asserts the token belongs to a PlatformAdmin, and ResolveTenant refuses
    // anything that isn't a User. Sanctum tokens are polymorphic and its
    // guard ignores the configured provider, so auth:sanctum alone would let
    // either identity through either door.
    Route::prefix('platform')->group(function () {
        Route::post('/login', [PlatformAuthController::class, 'login'])
            ->middleware('throttle:platform-login');

        Route::middleware(['auth:sanctum', 'platform'])->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);

            // The manual rail's equivalent of a payment webhook: the only
            // path by which a bank transfer becomes a paid plan.
            Route::get('/billing/pending', [SubscriptionReviewController::class, 'pending']);
            Route::post('/billing/invoices/{invoice}/approve', [SubscriptionReviewController::class, 'approve'])
                ->whereNumber('invoice');
            Route::post('/billing/invoices/{invoice}/reject', [SubscriptionReviewController::class, 'reject'])
                ->whereNumber('invoice');

            // Which currency a shop is billed in — separate from what it
            // SELLS in. Staff-only: the price ladders aren't at parity across
            // currencies, so self-service would be an arbitrage lever.
            Route::post('/subscriptions/{subscription}/billing-currency', [SubscriptionReviewController::class, 'billingCurrency'])
                ->whereNumber('subscription');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function () {
            // Reads stay open to a lapsed shop: its catalogue is its own
            // data, and hiding it to force payment would be taking something
            // away rather than withholding something new.
            Route::apiResource('products', ProductController::class)->only(['index', 'show']);
            Route::get('/categories', [CategoryController::class, 'index']);

            // Catalogue and inventory CHANGES. A lapsed shop keeps selling
            // and fulfilling what it already has; what it loses is the
            // ability to grow the catalogue.
            Route::middleware('subscription')->group(function () {
                Route::apiResource('products', ProductController::class)->except(['index', 'show']);
                Route::post('/products/{product}/variants', [ProductVariantController::class, 'store']);
                Route::match(['put', 'patch'], '/products/{product}/variants/{variant}', [ProductVariantController::class, 'update']);
                Route::post('/products/{product}/variants/{variant}/restock', [ProductVariantController::class, 'restock']);
            });
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            // Before /orders/{order} so model binding can't swallow it.
            Route::get('/orders/cancellation-reasons', [OrderController::class, 'cancellationReasons']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::match(['put', 'patch'], '/orders/{order}', [OrderController::class, 'update']);
            // Distinct actions, not status edits — each carries required
            // inputs and an audit trail a generic update can't enforce.
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
            Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatch']);

            // Per-tenant, not a platform catalogue: couriers are regional and
            // open-ended, unlike payment methods.
            //
            // Deliberately NOT gated by plan. Dispatch tracking looks like a
            // logistics upsell, but COD plus delivery is how most shops in
            // this market actually trade — gating it would gate the product.
            // See the note at the bottom of PlanFeature.
            Route::get('/delivery-providers', [DeliveryProviderController::class, 'index']);
            Route::middleware('subscription')->group(function () {
                Route::post('/delivery-providers', [DeliveryProviderController::class, 'store']);
                Route::match(['put', 'patch'], '/delivery-providers/{provider}', [DeliveryProviderController::class, 'update']);
                Route::delete('/delivery-providers/{provider}', [DeliveryProviderController::class, 'destroy']);
            });
            Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
            // The only place unit_cost margins surface, and the clearest
            // reason a growing shop upgrades.
            Route::get('/reports/sales-profit', [ReportController::class, 'salesProfit'])
                ->middleware('plan:profit_reports');
            Route::get('/tenant', [TenantController::class, 'show']);
            // Multipart can't be sent via a real PUT/PATCH, so clients POST
            // with _method — Route::match accepts both.
            Route::match(['put', 'patch'], '/tenant', [TenantController::class, 'update']);
            // No route takes a tenant parameter — all act on app('tenant'),
            // derived from the authenticated user, so a shop can only ever
            // touch its own. index() returns the full catalogue so the
            // settings screen doesn't need its own copy of what's possible.
            Route::get('/payments/methods', [PaymentMethodController::class, 'index']);
            Route::post('/payments/methods', [PaymentMethodController::class, 'upsert'])
                ->middleware('subscription');
            // Gated as a pair. status() is a read, but a shop that cannot
            // have a connected account has nothing to read, and leaving it
            // open would let the settings screen offer an onboarding button
            // that 402s on click.
            Route::middleware('plan:card_payments')->group(function () {
                Route::get('/payments/stripe/status', [StripeConnectController::class, 'status']);
                Route::post('/payments/stripe/onboarding-link', [StripeConnectController::class, 'link'])
                    ->middleware('subscription');
            });
            // Deliberately OUTSIDE the 'subscription' middleware. A shop
            // whose subscription has lapsed is exactly the shop that needs to
            // reach these — gating the renew button behind an active
            // subscription is the one bug here a customer could not recover
            // from without support.
            Route::get('/billing', [BillingController::class, 'show']);
            Route::get('/billing/invoices', [BillingController::class, 'invoices']);
            Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
            // The shop's transfer screenshot. Uploading it settles NOTHING —
            // the invoice stays pending until a human here approves it.
            Route::post('/billing/invoices/{invoice}/proof', [BillingController::class, 'proof']);
            Route::post('/billing/cancel', [BillingController::class, 'cancel']);

            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        });
    });
});
