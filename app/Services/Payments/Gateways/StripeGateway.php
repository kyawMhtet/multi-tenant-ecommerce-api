<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentEventType;
use App\Services\Payments\Data\PaymentInitiation;
use App\Services\Payments\Exceptions\InvalidWebhookSignature;
use App\Services\Stripe\StripeMoney;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The only class that knows Stripe exists. If you find "stripe" mentioned
 * outside this file and its config, the abstraction has leaked.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * The `stripe_account` option is what makes this a DIRECT charge: the
     * session is created on the shop's account, so the shop is merchant of
     * record and bears its own fees, refunds and chargebacks. Destination
     * charges were rejected — they'd make this platform liable for every
     * shop's disputes. A platform cut would be application_fee_amount here.
     *
     * expires_at is doing inventory work, not UX: stock is deducted at order
     * creation and the expired webhook is what releases it.
     */
    public function initiate(Order $order, TenantPaymentMethod $method): PaymentInitiation
    {
        $tenant = $order->tenant;

        if (blank($tenant->stripe_account_id)) {
            throw new RuntimeException('This shop has not finished connecting its Stripe account.');
        }

        $currency = strtolower($order->currency ?? $tenant->currency ?? 'usd');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => (string) $order->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => StripeMoney::toMinor((float) $order->total, $currency),
                    'product_data' => ['name' => 'Order '.$order->order_number],
                ],
            ]],
            'expires_at' => now()->addMinutes(
                max(30, (int) config('payments.stripe.session_expires_minutes'))
            )->timestamp,
            'success_url' => $this->storefrontUrl($order, config('payments.success_path')),
            'cancel_url' => $this->storefrontUrl($order, config('payments.cancel_path')),
            // Echoed on every webhook. Not used to find the order (the pending
            // payments row does that), but invaluable when supporting one.
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'tenant_id' => (string) $order->tenant_id,
            ],
        ], ['stripe_account' => $tenant->stripe_account_id]);

        return PaymentInitiation::redirect($session->url, $session->id);
    }

    /**
     * Verification uses the RAW body — re-encoding a decoded array changes the
     * bytes and the signature never matches, so getContent() is deliberate.
     *
     * The webhook secret is the PLATFORM's, not the connected account's: with
     * direct charges, a shop's events still arrive at the platform endpoint.
     *
     * Unrecognised event types return null; Stripe sends far more than any one
     * integration uses.
     */
    public function parseWebhook(Request $request): ?PaymentEvent
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('payments.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException $e) {
            throw new InvalidWebhookSignature(previous: $e);
        } catch (\UnexpectedValueException $e) {
            throw new InvalidWebhookSignature('Malformed webhook payload.', previous: $e);
        }

        $type = match ($event->type) {
            'checkout.session.completed' => PaymentEventType::Succeeded,
            'checkout.session.expired' => PaymentEventType::Expired,
            'checkout.session.async_payment_failed' => PaymentEventType::Failed,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $session = $event->data->object;

        // A completed session isn't necessarily a paid one: delayed payment
        // methods complete while payment is still pending. Only 'paid' counts.
        if ($type === PaymentEventType::Succeeded && ($session->payment_status ?? null) !== 'paid') {
            return null;
        }

        return new PaymentEvent(
            type: $type,
            transactionRef: $session->id,
            amount: isset($session->amount_total)
                ? StripeMoney::fromMinor((int) $session->amount_total, (string) $session->currency)
                : null,
            raw: $event->toArray(),
        );
    }

    private function storefrontUrl(Order $order, string $path): string
    {
        $base = str_replace('{slug}', $order->tenant->slug, (string) config('payments.storefront_url'));

        return rtrim($base, '/').str_replace('{order}', (string) $order->id, $path);
    }
}
