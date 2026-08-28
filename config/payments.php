<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | These are the PLATFORM's credentials, not any individual shop's. With
    | Stripe Connect direct charges, every charge is created using this one
    | secret key while naming the shop's connected account (stored on
    | tenants.stripe_account_id) as the recipient — so the money lands in
    | the shop's Stripe balance, never this platform's, and the shop bears
    | its own refunds and chargebacks.
    |
    | That's why there are no per-tenant secrets anywhere in this app: a
    | shop is identified by an `acct_...` id, which is not a credential.
    |
    | webhook_secret is separate from the API key and is what proves an
    | incoming webhook genuinely came from Stripe. Stripe gives you a
    | different one per endpoint you register — use the one shown for the
    | endpoint you point at /api/v1/webhooks/stripe.
    |
    | session_expires_minutes controls how long a customer has to complete
    | checkout. It matters beyond UX: stock is reserved when the order is
    | created, and Stripe's checkout.session.expired webhook is what
    | releases it, so this is effectively the inventory hold time. Stripe
    | requires at least 30 minutes.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'session_expires_minutes' => (int) env('STRIPE_SESSION_EXPIRES_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storefront return URLs
    |--------------------------------------------------------------------------
    |
    | Where Stripe sends the customer's browser back to after they finish or
    | abandon checkout. {slug} is replaced with the shop's slug so the
    | customer lands back on the right storefront, and {order} with the
    | order id.
    |
    | Worth being clear about what these pages are for: they show the
    | customer a result, and nothing more. Neither one may mark an order as
    | paid — a browser redirect can be faked, lost, or closed, so the
    | webhook is the only thing permitted to change payment status. The
    | success page should read the order's real status from the API rather
    | than assuming payment succeeded just because the customer arrived
    | there.
    |
    */

    'storefront_url' => env('STOREFRONT_URL', 'http://{slug}.localhost:3000'),

    // Where Stripe returns a shop OWNER after Connect onboarding — the
    // admin app, not the customer-facing storefront. Separate setting
    // because the two are different applications and, in production,
    // likely different hosts.
    'admin_url' => env('ADMIN_URL', 'http://localhost:3000'),
    'success_path' => env('STOREFRONT_SUCCESS_PATH', '/orders/{order}/success'),
    'cancel_path' => env('STOREFRONT_CANCEL_PATH', '/orders/{order}/cancelled'),

];
