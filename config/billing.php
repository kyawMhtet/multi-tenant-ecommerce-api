<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What this file is NOT
    |--------------------------------------------------------------------------
    |
    | config/payments.php is about money flowing customer -> shop: Connect
    | direct charges, where the SHOP is merchant of record and bears its own
    | fees and chargebacks. This file is the opposite direction — money
    | flowing shop -> platform, where the platform is merchant of record.
    |
    | Same vendor, opposite direction, opposite liability. They share nothing
    | but the SDK, which is why they get separate config, a separate webhook
    | endpoint and a separate signing secret.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Billing currency
    |--------------------------------------------------------------------------
    |
    | A shop is billed in ITS OWN currency, into an account in its own country.
    |
    | This was originally one platform-wide currency (THB, since the platform's
    | Stripe account is Thai), on the reasoning that a Yangon shop trading in
    | Kyat should still pay this platform in Baht. That was wrong, and it broke
    | the one thing the manual rail exists for: a shop inside Myanmar cannot
    | easily wire Baht to a Thai bank — capital controls make it genuinely
    | hard, not merely inconvenient. A manual rail that only accepts THB does
    | not serve the customers it was built for.
    |
    | tenants.currency is the key, and it is a good one precisely because it is
    | already immutable: chosen at signup and refused by UpdateTenantRequest,
    | since money columns carry no currency tag. So a shop cannot migrate
    | itself to whichever currency is cheapest this week — the same property
    | that protects order history protects the price list.
    |
    | A tenant whose currency has no billing entry (USD today) falls back to
    | the default rather than failing: they can still pay, just in Baht.
    |
    */

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'THB'),

    /*
    |--------------------------------------------------------------------------
    | Per-currency pricing and receiving accounts
    |--------------------------------------------------------------------------
    |
    | Each entry needs BOTH halves to be usable: an amount the shop is asked
    | for, and somewhere for the money to land. A currency with prices but no
    | account is a bill nobody can pay; an account with no prices is a transfer
    | of an unknown amount that a human then has to guess at.
    |
    | `amount` is in major units (750 = 750 THB), matching how money is written
    | everywhere else in this app. THESE NUMBERS ARE PLACEHOLDERS and have not
    | been confirmed against anything — set them deliberately before taking a
    | single real payment.
    |
    | `stripe_price_id` is per plan AND per currency: a Stripe Price carries
    | exactly one currency, and Stripe issues DIFFERENT ids in test and live
    | mode. Null means that plan cannot be sold by card in that currency — the
    | normal state for MMK, which Stripe does not support at all. That is not a
    | misconfiguration: it is the reason the manual rail is the primary path
    | here rather than a fallback.
    |
    | `manual` holds the PLATFORM's own receiving details — the account a shop
    | transfers its subscription fee into. Not the shop's bank details, which
    | live on tenant_payment_methods and face the shop's own customers. Not
    | secret either (they get shown to every shop that picks transfer), but
    | env-held so they stay out of version control and can differ per
    | deployment.
    |
    */

    'currencies' => [

        'THB' => [
            'plans' => [
                'starter' => [
                    'amount' => (float) env('BILLING_THB_STARTER_AMOUNT', 300),
                    'stripe_price_id' => env('BILLING_THB_STARTER_PRICE_ID'),
                ],
                'pro' => [
                    'amount' => (float) env('BILLING_THB_PRO_AMOUNT', 750),
                    'stripe_price_id' => env('BILLING_THB_PRO_PRICE_ID'),
                ],
            ],
            'manual' => [
                'bank_name' => env('BILLING_THB_BANK_NAME'),
                'account_name' => env('BILLING_THB_ACCOUNT_NAME'),
                'account_number' => env('BILLING_THB_ACCOUNT_NUMBER'),
                'instructions' => env('BILLING_THB_INSTRUCTIONS'),
            ],
        ],

        'MMK' => [
            'plans' => [
                'starter' => [
                    'amount' => (float) env('BILLING_MMK_STARTER_AMOUNT', 30000),
                    // Stripe does not support MMK. Card is structurally
                    // unavailable here, not merely unconfigured.
                    'stripe_price_id' => null,
                ],
                'pro' => [
                    'amount' => (float) env('BILLING_MMK_PRO_AMOUNT', 75000),
                    'stripe_price_id' => null,
                ],
            ],
            'manual' => [
                'bank_name' => env('BILLING_MMK_BANK_NAME'),
                'account_name' => env('BILLING_MMK_ACCOUNT_NAME'),
                'account_number' => env('BILLING_MMK_ACCOUNT_NUMBER'),
                'instructions' => env('BILLING_MMK_INSTRUCTIONS'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | New shops start on the top plan so they see what they are being asked to
    | pay for. A trial that only shows the cheap tier gives the shop no way to
    | discover the features it would be upgrading for, and no reason to.
    |
    | Before this existed, AuthService::register() set no end date at all and
    | every shop was on an unlimited trial.
    |
    */

    'trial_plan' => env('BILLING_TRIAL_PLAN', 'pro'),
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | How long a shop keeps full access after its period ends unpaid, before
    | dropping to read-only. Not generosity — correctness. A card expires, a
    | transfer takes days to clear, an owner is travelling. Cutting access the
    | second a period ends punishes ordinary life, and a shop that cannot trade
    | is a shop that cannot pay.
    |
    | The manual rail needs the longer window: a transfer has to be sent,
    | arrive, and then be checked by a human here, and none of those is
    | instant. A card either works or it doesn't.
    |
    */

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),
    'manual_grace_days' => (int) env('BILLING_MANUAL_GRACE_DAYS', 14),

    // Global kill switch for the transfer rail, independent of whether any
    // particular currency has an account configured.
    'manual_enabled' => (bool) env('BILLING_MANUAL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Stripe (platform account)
    |--------------------------------------------------------------------------
    |
    | The secret key is shared with config/payments.php — same platform
    | account either way, and StripeClient is bound once in AppServiceProvider.
    | The WEBHOOK SECRET is not shared and must not be: Stripe issues a
    | different signing secret per registered endpoint, and billing events
    | arrive at a different endpoint than Connect events. One secret for both
    | would let either endpoint accept the other's traffic.
    |
    */

    'stripe' => [
        'webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),
    ],

    // Where Stripe returns the shop OWNER after paying. The admin app, not
    // the storefront. Landing here is NOT proof of payment — the page must
    // re-read the real subscription state, because only the webhook may
    // change it.
    'return_path' => env('BILLING_RETURN_PATH', '/settings/billing'),

];
