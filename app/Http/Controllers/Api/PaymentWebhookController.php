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
     * One route per gateway, so the provider is known from the URL rather
     * than sniffed from the payload — signature schemes differ completely
     * between providers, and a shared endpoint would mean every gateway's
     * parsing bugs share a blast radius.
     *
     * Deliberately outside both 'auth:sanctum' and 'tenant': this is a
     * server-to-server call with no user and no X-Tenant-Slug header. The
     * signature check inside the gateway IS the authentication, which is
     * why parseWebhook() verifies and parses as one inseparable step.
     *
     * Always 200 once the signature is valid, even when the event is
     * ignored. A non-2xx tells the provider to retry, and there is nothing
     * to gain from retrying an event we've deliberately chosen not to act
     * on — that just turns a no-op into repeated traffic. Genuine
     * signature failures still surface as 400 via InvalidWebhookSignature.
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
