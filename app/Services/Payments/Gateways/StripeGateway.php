<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Models\TenantPaymentMethod;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentEventType;
use App\Services\Payments\Data\PaymentInitiation;
use App\Services\Payments\Exceptions\InvalidWebhookSignature;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The only class in this application that knows Stripe exists.
 *
 * Everything it returns is in the app's own vocabulary (PaymentInitiation,
 * PaymentEvent), so OrderService, the controllers and the storefront stay
 * provider-agnostic. If you ever find "stripe" mentioned outside this file
 * and its config, the abstraction has leaked.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Creates a Stripe-hosted Checkout Session as a DIRECT charge on the
     * shop's connected account.
     *
     * The `stripe_account` option is what makes this a direct charge: the
     * session is created *on the shop's account*, so funds settle in the
     * shop's balance, the shop is merchant of record and appears on the
     * customer's statement, and the shop bears its own refunds and
     * chargebacks. The alternative (destination charges) would route money
     * through this platform and make us liable for every shop's disputes —
     * unbounded risk for a small SaaS. If a platform fee is ever wanted,
     * it's an `application_fee_amount` here, with no change to this model.
     *
     * Amounts are sent in the currency's smallest unit, which is why the
     * total is multiplied. That conversion is currency-specific and a
     * classic source of 100x errors — see toMinorUnits().
     *
     * expires_at is doing real inventory work, not just UX: stock is
     * deducted when the order is created, and Stripe's
     * checkout.session.expired webhook is what releases it again. This is
     * effectively the hold time on the shop's inventory.
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
                    'unit_amount' => $this->toMinorUnits((float) $order->total, $currency),
                    'product_data' => ['name' => 'Order '.$order->order_number],
                ],
            ]],
            'expires_at' => now()->addMinutes(
                max(30, (int) config('payments.stripe.session_expires_minutes'))
            )->timestamp,
            'success_url' => $this->storefrontUrl($order, config('payments.success_path')),
            'cancel_url' => $this->storefrontUrl($order, config('payments.cancel_path')),
            // Echoed back on every webhook for this session. Not used to
            // find the order — that's done via the pending payments row
            // keyed on the session id — but invaluable when supporting a
            // payment that went wrong.
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'tenant_id' => (string) $order->tenant_id,
            ],
        ], ['stripe_account' => $tenant->stripe_account_id]);

        return PaymentInitiation::redirect($session->url, $session->id);
    }

    /**
     * Verifies the signature, then translates the handful of Stripe events
     * this app cares about. Every other event type returns null — Stripe
     * sends far more than any one integration uses, and ignoring the rest
     * is normal.
     *
     * Signature verification uses the RAW request body. Re-encoding a
     * decoded array would change the bytes and the signature would never
     * match, so getContent() is used deliberately.
     *
     * Note the webhook secret is the platform's, not the connected
     * account's: with direct charges, Connect events for a shop's account
     * still arrive at the platform's endpoint.
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

        // A completed session isn't necessarily a paid one: with delayed
        // payment methods the session completes while payment is still
        // pending, and treating that as success would mark an order paid
        // for money that hasn't arrived. Only 'paid' counts.
        if ($type === PaymentEventType::Succeeded && ($session->payment_status ?? null) !== 'paid') {
            return null;
        }

        return new PaymentEvent(
            type: $type,
            transactionRef: $session->id,
            amount: isset($session->amount_total)
                ? $this->fromMinorUnits((int) $session->amount_total, (string) $session->currency)
                : null,
            raw: $event->toArray(),
        );
    }

    /**
     * Most currencies use two decimal places, but several use none — and
     * for those Stripe expects the plain integer amount. Sending 5000 for
     * a ¥5000 order when Stripe expects 5000 is correct; multiplying it to
     * 500000 would charge a hundred times too much. MMK and THB are both
     * relevant here: THB is a normal two-decimal currency, while MMK is
     * zero-decimal.
     *
     * Rounding before casting matters too — (int) on a float like 19.99*100
     * can land on 1998 through binary representation, silently
     * undercharging by a cent.
     */
    private function toMinorUnits(float $amount, string $currency): int
    {
        return $this->isZeroDecimal($currency)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    private function fromMinorUnits(int $amount, string $currency): float
    {
        return $this->isZeroDecimal($currency) ? (float) $amount : $amount / 100;
    }

    /**
     * Stripe's zero-decimal currency list — verify against
     * https://docs.stripe.com/currencies#zero-decimal before adding to it.
     *
     * Note THB (Thailand, the market driving this integration) is a normal
     * two-decimal currency and correctly absent. MMK is absent too: ISO
     * 4217 assigns the Kyat two decimals, and it is not on Stripe's
     * zero-decimal list — largely moot in practice, since Stripe doesn't
     * operate in Myanmar and those tenants will use a different gateway.
     *
     * Resist adding a currency here on intuition. Getting an entry wrong
     * in either direction is a silent 100x charge error, not a crash.
     */
    private function isZeroDecimal(string $currency): bool
    {
        return in_array(strtolower($currency), [
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
            'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ], true);
    }

    private function storefrontUrl(Order $order, string $path): string
    {
        $base = str_replace('{slug}', $order->tenant->slug, (string) config('payments.storefront_url'));

        return rtrim($base, '/').str_replace('{order}', (string) $order->id, $path);
    }
}
