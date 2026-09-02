<?php

namespace App\Services\Billing\Data;

/**
 * What the admin app must do next to get the shop paid up.
 *
 * The two rails genuinely end in different places, and flattening that into
 * "a URL" would lose it: one sends the owner to Stripe, the other shows them
 * bank details and waits days for a human. A client switching on this can
 * render both without guessing.
 */
enum BillingInitiationType: string
{
    /** Send the shop owner's browser to the provider's hosted page. */
    case Redirect = 'redirect';

    /**
     * Show bank details and an invoice to pay by transfer. Nothing is
     * pending with any provider — the shop pays a bank, uploads a
     * screenshot, and a human here decides.
     */
    case Transfer = 'transfer';
}
