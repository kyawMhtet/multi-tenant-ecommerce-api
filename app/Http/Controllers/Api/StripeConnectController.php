<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeConnectService;
use Illuminate\Http\JsonResponse;

class StripeConnectController extends Controller
{
    public function __construct(private readonly StripeConnectService $connect) {}

    /**
     * Takes no parameter: the tenant is app('tenant'), derived from the
     * authenticated user, so there's no input that could name another shop.
     */
    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->connect->status(app('tenant'))]);
    }

    /**
     * Returns a URL rather than issuing a redirect — a separate Next.js app
     * controls its own navigation.
     *
     * return_url is where Stripe sends the owner whether they finish OR
     * abandon. It is NOT proof of success: that page must re-check status().
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
