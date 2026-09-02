<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The shop's subscription has lapsed past its grace period, so it is
 * read-only.
 *
 * 402 rather than 403 throughout the billing layer. 403 says "you are not
 * allowed to do this", which is wrong and unactionable — the shop IS allowed,
 * it simply has not paid. 402 says "pay and this works", which is exactly the
 * situation, and it lets the admin app show an upgrade prompt without having
 * to sniff the message text to tell billing apart from a genuine permission
 * failure.
 */
class SubscriptionInactiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This shop\'s subscription is no longer active.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Your subscription has ended, so changes are paused. Your shop, storefront and orders are all still running — renew to start making changes again.',
            'reason' => 'subscription_inactive',
        ], 402);
    }
}
