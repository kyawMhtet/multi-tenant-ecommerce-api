<?php

namespace App\Services\Payments\Data;

/**
 * How the storefront should collect payment for a given method.
 *
 * Only Redirect and None are produced today — every gateway on the roadmap
 * (Stripe Checkout, 2C2P's hosted page, MyanMyanPay) is redirect-based,
 * which is also the option that keeps card data furthest from our servers
 * and so keeps PCI scope at its lightest tier.
 *
 * FormPost and ClientToken are declared but unimplemented on purpose: they
 * cost nothing to name, and naming them is what lets the storefront write
 * an exhaustive switch today that won't need restructuring when a gateway
 * eventually requires one. Add the handling when a real provider forces
 * it, not before.
 */
enum PaymentInitiationType: string
{
    /** Send the browser to $url. */
    case Redirect = 'redirect';

    /** Render a form POSTing $fields to $url (some older gateways require signed form posts). */
    case FormPost = 'form_post';

    /** Hand $token to a client-side SDK (e.g. an embedded card form). */
    case ClientToken = 'client_token';

    /** No online collection — the order simply waits for a human to confirm payment. */
    case None = 'none';
}
