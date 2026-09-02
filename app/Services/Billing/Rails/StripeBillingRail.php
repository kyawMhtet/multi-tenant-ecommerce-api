<?php

namespace App\Services\Billing\Rails;

use App\Exceptions\BillingActionUnavailableException;
use App\Models\Subscription;
use App\Services\Billing\BillingCurrency;
use App\Services\Billing\Contracts\BillingRail;
use App\Services\Billing\Data\BillingEvent;
use App\Services\Billing\Data\BillingEventType;
use App\Services\Billing\Data\BillingInitiation;
use App\Services\Payments\Exceptions\InvalidWebhookSignature;
use App\Services\Stripe\StripeMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe Billing on the PLATFORM's own account.
 *
 * The single most important line in this class is one that is absent: there
 * is no ['stripe_account' => ...] option anywhere. StripeGateway passes that
 * on every call because a customer's payment must land on the SHOP's
 * connected account. Here the platform is the merchant and the shop is the
 * customer, so the charge belongs on the platform account — adding the
 * connected-account option would bill the shop on its own account and pay the
 * money to itself.
 *
 * The customer id stored here (cus_...) is therefore a completely different
 * thing from tenants.stripe_account_id (acct_...), despite both being
 * "the shop's Stripe id".
 */
class StripeBillingRail implements BillingRail
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function name(): string
    {
        return 'stripe';
    }

    /**
     * A price id is per plan, per currency AND per mode: a Stripe Price
     * carries exactly one currency, and Stripe issues different ids in test
     * and live. An unset one means this deployment cannot sell that plan by
     * card in that currency — a normal state during setup, and a PERMANENT
     * one for MMK, which Stripe does not support at all.
     */
    public function isAvailable(string $plan, string $currency): bool
    {
        return filled(config('payments.stripe.secret'))
            && filled(BillingCurrency::stripePriceFor($currency, $plan));
    }

    public function initiate(Subscription $subscription, string $plan): BillingInitiation
    {
        $currency = BillingCurrency::for($subscription);
        $priceId = BillingCurrency::stripePriceFor($currency, $plan);

        // Reached only if a caller skipped isAvailable(). Failing here beats
        // sending Stripe a null price and getting an opaque API error.
        if ($priceId === null) {
            throw new BillingActionUnavailableException(
                "Card payment is not available for this plan in {$currency}."
            );
        }

        $customerId = $subscription->external_customer_ref ?? $this->createCustomer($subscription);
        $returnUrl = $this->returnUrl($subscription);

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            // The price carries the amount AND the currency, so nothing here
            // converts money. Our own config amount is only used for invoice
            // records; if the two ever disagree, the price id is the one that
            // actually charged the card.
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $returnUrl.'?billing=success',
            'cancel_url' => $returnUrl.'?billing=cancelled',
            'client_reference_id' => (string) $subscription->tenant_id,
            'metadata' => [
                'tenant_id' => (string) $subscription->tenant_id,
                'plan' => $plan,
            ],
            // Copied onto the SUBSCRIPTION, not just this session, and that
            // is what makes the webhook work: invoice.paid and
            // customer.subscription.* arrive carrying the subscription and
            // know nothing about the checkout session that started it.
            // Without this the webhook would have to reverse-look-up a
            // customer id to find the tenant.
            'subscription_data' => [
                'metadata' => [
                    'tenant_id' => (string) $subscription->tenant_id,
                    'plan' => $plan,
                ],
            ],
        ]);

        return BillingInitiation::redirect($session->url);
    }

    /**
     * Persisted BEFORE it is used, same reasoning as
     * StripeConnectService::createAccount(): reversed, a failed save orphans
     * a Stripe customer and the next attempt makes another, so one shop ends
     * up with several customers and its payment history splits between them.
     */
    private function createCustomer(Subscription $subscription): string
    {
        $tenant = $subscription->tenant;

        $customer = $this->stripe->customers->create([
            'email' => $tenant->owner_email,
            'name' => $tenant->name,
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_slug' => $tenant->slug,
            ],
        ]);

        DB::transaction(function () use ($subscription, $customer) {
            Subscription::whereKey($subscription->id)->update(['external_customer_ref' => $customer->id]);
        });

        $subscription->external_customer_ref = $customer->id;

        return $customer->id;
    }

    /**
     * cancel_at_period_end, never a delete: the shop paid for this month and
     * keeps it. Deleting would cut access the instant they clicked cancel,
     * which is taking back something already bought.
     */
    public function cancel(Subscription $subscription): void
    {
        if (blank($subscription->external_subscription_ref)) {
            return;
        }

        $this->stripe->subscriptions->update(
            $subscription->external_subscription_ref,
            ['cancel_at_period_end' => true],
        );
    }

    /**
     * The webhook secret is `billing.stripe.webhook_secret`, NOT
     * `payments.stripe.webhook_secret`. Stripe issues a different signing
     * secret per registered endpoint, and these are two endpoints carrying
     * opposite directions of money. Pointing both at one secret would let
     * this endpoint accept Connect traffic and vice versa — subscription
     * events hunting for an order that does not exist.
     *
     * Verification uses the RAW body: re-encoding a decoded array changes the
     * bytes and the signature never matches, so getContent() is deliberate.
     *
     * Unrecognised types return null. Stripe sends far more than any one
     * integration uses, and a 500 on an unknown type would have it retry
     * something that can never succeed.
     */
    public function parseWebhook(Request $request): ?BillingEvent
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('billing.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException $e) {
            throw new InvalidWebhookSignature(previous: $e);
        } catch (UnexpectedValueException $e) {
            throw new InvalidWebhookSignature('Malformed webhook payload.', previous: $e);
        }

        $raw = $event->toArray();

        return match ($event->type) {
            'invoice.paid' => $this->invoiceEvent($raw, BillingEventType::Paid),
            'invoice.payment_failed' => $this->invoiceEvent($raw, BillingEventType::PaymentFailed),
            'customer.subscription.deleted' => $this->subscriptionEvent($raw),
            default => null,
        };
    }

    /**
     * Read from the decoded array rather than the StripeObject: accessing a
     * key an object does not carry logs a "Stripe Notice: Undefined property"
     * error, and these payloads legitimately differ in shape.
     *
     * Which shape matters here. Stripe moved an invoice's subscription and its
     * metadata under `parent.subscription_details` in the 2025 API versions,
     * having previously had them at `subscription` / `subscription_details`.
     * Both are checked because the account's API version — not this code —
     * decides which arrives, and a silent null here would make every payment
     * unresolvable.
     */
    private function invoiceEvent(array $raw, BillingEventType $type): BillingEvent
    {
        $invoice = data_get($raw, 'data.object', []);
        $currency = (string) data_get($invoice, 'currency', 'thb');

        $amountMinor = data_get($invoice, 'amount_paid') ?? data_get($invoice, 'amount_due');

        return new BillingEvent(
            type: $type,
            subscriptionRef: data_get($invoice, 'subscription')
                ?? data_get($invoice, 'parent.subscription_details.subscription')
                ?? data_get($invoice, 'subscription_details.subscription'),
            customerRef: data_get($invoice, 'customer'),
            invoiceRef: data_get($invoice, 'id'),
            tenantId: $this->intOrNull($this->subscriptionMetadata($invoice)['tenant_id'] ?? null),
            plan: $this->subscriptionMetadata($invoice)['plan'] ?? null,
            // What Stripe says was actually charged, converted from minor
            // units. Deliberately NOT validated against our own config the way
            // the order webhook checks against $order->total: there we set the
            // amount, here Stripe does, and proration means our figure is the
            // wrong reference rather than a safety check.
            amount: $amountMinor === null ? null : StripeMoney::fromMinor((int) $amountMinor, $currency),
            currency: strtoupper($currency),
            periodStart: $this->timestamp(
                data_get($invoice, 'lines.data.0.period.start') ?? data_get($invoice, 'period_start')
            ),
            periodEnd: $this->timestamp(
                data_get($invoice, 'lines.data.0.period.end') ?? data_get($invoice, 'period_end')
            ),
            raw: $raw,
        );
    }

    /** @return array<string, mixed> */
    private function subscriptionMetadata(array $invoice): array
    {
        return (array) (
            data_get($invoice, 'parent.subscription_details.metadata')
            ?? data_get($invoice, 'subscription_details.metadata')
            ?? []
        );
    }

    private function subscriptionEvent(array $raw): BillingEvent
    {
        $subscription = data_get($raw, 'data.object', []);

        return new BillingEvent(
            type: BillingEventType::Cancelled,
            subscriptionRef: data_get($subscription, 'id'),
            customerRef: data_get($subscription, 'customer'),
            tenantId: $this->intOrNull(data_get($subscription, 'metadata.tenant_id')),
            plan: data_get($subscription, 'metadata.plan'),
            raw: $raw,
        );
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::createFromTimestamp((int) $value);
    }

    /** Metadata values are always strings on Stripe's side. */
    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function returnUrl(Subscription $subscription): string
    {
        $base = str_replace('{slug}', $subscription->tenant->slug, (string) config('payments.admin_url'));

        return rtrim($base, '/').config('billing.return_path');
    }
}
