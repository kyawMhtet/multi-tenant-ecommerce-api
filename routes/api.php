<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
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
    // No tenant context required yet: the client is proving who it is,
    // not asking for tenant-scoped data. See ResolveTenant middleware docs.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Self-serve shop signup — necessarily outside both auth:sanctum and
    // the 'tenant' middleware: there is no authenticated user and no
    // tenant yet, the tenant is what this creates. See
    // AuthService::register().
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

    // Public storefront read: no auth, and deliberately not behind the
    // 'tenant' alias either — that middleware reads an X-Tenant-Slug
    // header, which a plain clicked link can't send. The slug itself
    // identifies both the variant and (via StorefrontProductService) its
    // tenant.
    Route::get('/public/products/{slug}', [PublicProductController::class, 'show']);

    // Public storefront checkout: unlike the product page above, this is
    // always a JS-driven request from a storefront frontend that already
    // knows its own tenant (e.g. from the subdomain it's running on), so
    // it can attach X-Tenant-Slug the same way an authenticated API
    // client would — the 'tenant' alias applies here on its own, with no
    // auth:sanctum in front of it. ResolveTenant's cross-check against
    // $request->user() is a no-op with no user, so the entire
    // tenant-isolation guarantee for this write path is the ambient
    // scope it binds; see StorePublicOrderRequest and
    // OrderService::createOnlineOrder().
    Route::middleware('tenant')->group(function () {
        Route::post('/public/orders', [PublicOrderController::class, 'store'])
            ->middleware('throttle:public-orders');

        // Storefront shop header/footer (logo, hours, contact), catalog
        // listing, and category nav — all header-based like public/orders
        // rather than slug-in-URL like public/products/{slug}, because a
        // storefront page always knows its own tenant from the subdomain
        // it's served on. Sharing one throttle (public-shop, despite the
        // name — it's really "public storefront read"): all three are hit
        // on every storefront page load, and mobile carriers here put very
        // large numbers of users behind very few egress IPs, so the
        // general per-IP api limit would 429 real customers browsing a
        // shop.
        Route::middleware('throttle:public-shop')->group(function () {
            Route::get('/public/shop', [PublicShopController::class, 'show']);
            Route::get('/public/products', [PublicProductController::class, 'index']);
            // Same controller method as the admin route below — the query
            // (Category::orderBy('name')->get()) has no cost/sensitive data
            // and no business logic beyond the ambient tenant scope, so
            // there's nothing admin-specific to duplicate for a public
            // category nav.
            Route::get('/public/categories', [CategoryController::class, 'index']);
            // Which payment options to render at checkout. Same
            // header-scoped, throttled group as the rest of the storefront
            // reads — it's fetched on the checkout screen of every order.
            Route::get('/public/payment-methods', [PublicPaymentMethodController::class, 'index']);
        });
    });

    // Gateway webhooks. No auth and no tenant middleware by necessity:
    // these are server-to-server calls carrying neither a token nor an
    // X-Tenant-Slug header. The provider's signature is the authentication
    // (verified inside each gateway's parseWebhook), and the tenant is
    // derived from whichever order the transaction reference resolves to.
    //
    // {gateway} is matched against the manager's known drivers, so an
    // unknown value fails loudly rather than falling through to a generic
    // handler. This is the ONLY path allowed to mark an order paid — a
    // browser redirect can be faked, lost or closed, so the storefront's
    // success page only ever displays state, never sets it.
    Route::post('/webhooks/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:payment-webhooks')
        ->where('gateway', 'stripe');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function () {
            Route::apiResource('products', ProductController::class);
            Route::post('/products/{product}/variants', [ProductVariantController::class, 'store']);
            Route::match(['put', 'patch'], '/products/{product}/variants/{variant}', [ProductVariantController::class, 'update']);
            Route::post('/products/{product}/variants/{variant}/restock', [ProductVariantController::class, 'restock']);
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::match(['put', 'patch'], '/orders/{order}', [OrderController::class, 'update']);
            Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
            Route::get('/reports/sales-profit', [ReportController::class, 'salesProfit']);
            Route::get('/tenant', [TenantController::class, 'show']);
            // Multipart (a logo upload) can't be sent via a real PUT/PATCH,
            // so clients POST with _method — Route::match accepts both.
            Route::match(['put', 'patch'], '/tenant', [TenantController::class, 'update']);
            // Stripe Connect onboarding for this shop. Neither route takes
            // a tenant parameter — both act on app('tenant'), derived from
            // the authenticated user, so a shop can only ever connect or
            // inspect its own account.
            // Which payment methods this shop accepts. index() returns the
            // full catalogue (configured or not) so the settings screen
            // doesn't need its own copy of what's possible.
            Route::get('/payments/methods', [PaymentMethodController::class, 'index']);
            Route::post('/payments/methods', [PaymentMethodController::class, 'upsert']);
            Route::get('/payments/stripe/status', [StripeConnectController::class, 'status']);
            Route::post('/payments/stripe/onboarding-link', [StripeConnectController::class, 'link']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        });
    });
});
