<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WebhookProcessor $processor,
    ) {}

    /**
     * One route per gateway, so the provider is known from the URL rather than
     * sniffed from the payload — signature schemes differ completely, and a
     * shared endpoint would give every gateway's parsing bugs one blast radius.
     *
     * Outside both 'auth:sanctum' and 'tenant': there's no user and no header
     * on a server-to-server call. The signature check IS the authentication.
     *
     * Always 200 once the signature is valid, even for ignored events: a
     * non-2xx just makes the provider retry a no-op. Signature failures still
     * surface as 400.
     */
    public function handle(Request $request, string $gateway)
    {
        $event = $this->gateways->gateway($gateway)->parseWebhook($request);

        if ($event !== null) {
            $this->processor->process($gateway, $event);
        }

        return response()->noContent(Response::HTTP_OK);
    }
}
