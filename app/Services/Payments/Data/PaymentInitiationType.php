<?php

namespace App\Services\Payments\Data;

/**
 * How the storefront should collect payment. Only Redirect and None are
 * produced today — every gateway on the roadmap is redirect-based, which also
 * keeps card data furthest from our servers.
 *
 * FormPost and ClientToken are named but unimplemented on purpose: naming them
 * lets the storefront write an exhaustive switch that won't need restructuring
 * later. Add handling when a provider forces it, not before.
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
