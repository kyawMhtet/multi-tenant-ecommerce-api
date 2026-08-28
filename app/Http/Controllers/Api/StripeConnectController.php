<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeConnectService;
use Illuminate\Http\JsonResponse;

class StripeConnectController extends Controller
{
    public function __construct(private readonly StripeConnectService $connect) {}

    /**
     * Whether this shop can accept card payments yet, asked of Stripe
     * live rather than read from a local flag — see the service for why.
     *
     * Takes no parameter: the tenant is app('tenant'), derived by
     * ResolveTenant from the authenticated user, so there's no input a
     * caller could supply to inspect another shop's account.
     */
    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->connect->status(app('tenant'))]);
    }

    /**
     * Starts (or resumes) Stripe-hosted onboarding.
     *
     * Returns a URL for the client to redirect to rather than issuing a
     * redirect itself: this is a JSON API consumed by a separate Next.js
     * admin app, which needs to control the navigation.
     *
     * Both URLs point back at the admin settings page. return_url is where
     * Stripe sends the owner when they finish OR abandon — it is NOT proof
     * of success, so that page must re-check status() rather than assume
     * the shop is now connected.
     */
    public function link(): JsonResponse
    {
        $tenant = app('tenant');
        $settingsUrl = $this->settingsUrl($tenant->slug);

        $url = $this->connect->createOnboardingLink(
            $tenant,
            returnUrl: $settingsUrl.'?stripe=return',
            refreshUrl: $settingsUrl.'?stripe=refresh',
        );

        return response()->json(['data' => ['url' => $url]]);
    }

    private function settingsUrl(string $slug): string
    {
        $base = str_replace('{slug}', $slug, (string) config('payments.admin_url'));

        return rtrim($base, '/').'/settings/payments';
    }
}
