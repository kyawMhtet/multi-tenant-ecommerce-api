<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingRailManager;
use App\Services\Billing\BillingWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Subscription callbacks — money flowing shop -> platform.
 *
 * A separate endpoint from PaymentWebhookController, which handles Connect
 * callbacks for orders. They are not merged, and that is the point: each
 * verifies against its OWN signing secret, because Stripe issues a different
 * one per registered endpoint. One shared endpoint would accept either kind of
 * traffic on either secret, so a subscription event would go hunting for an
 * order that does not exist.
 *
 * Outside both 'auth:sanctum' and 'tenant': a server-to-server call carries
 * neither a token nor a slug. The signature check IS the authentication.
 */
class BillingWebhookController extends Controller
{
    public function __construct(
        private readonly BillingRailManager $rails,
        private readonly BillingWebhookProcessor $processor,
    ) {}

    /**
     * Always 200 once the signature is valid, even for events this app
     * ignores — a non-2xx just makes the provider retry a no-op. Signature
     * failures still surface as 400 via InvalidWebhookSignature::render().
     */
    public function handle(Request $request, string $rail)
    {
        $event = $this->rails->rail($rail)->parseWebhook($request);

        if ($event !== null) {
            $this->processor->process($event);
        }

        return response()->noContent(Response::HTTP_OK);
    }
}
