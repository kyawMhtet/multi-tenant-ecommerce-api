<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Which currency a shop is billed in, what it costs, and where the money goes.
 *
 * The whole reason this exists: a shop inside Myanmar cannot easily wire Baht
 * to a Thai bank, so billing every tenant in one platform currency would have
 * broken the manual rail for exactly the customers it was built for. Each shop
 * pays in its own currency, into an account in its own country.
 *
 * A thin static reader over config, like SupportedCurrency — no state, and
 * every answer is a deployment fact rather than a decision.
 */
class BillingCurrency
{
    /**
     * What a shop pays the PLATFORM in. Deliberately not the same question as
     * what it sells in.
     *
     * Resolution order:
     *   1. subscriptions.billing_currency — an explicit override, set only by
     *      platform staff. A value here means someone decided; it is never
     *      ambient state.
     *   2. tenants.currency — the shop's selling currency, right for almost
     *      every shop (a Yangon shop sells Kyat and banks in Kyat), which is
     *      why the override is null by default and nobody has to think about
     *      it.
     *   3. billing.default_currency — for a selling currency we cannot receive
     *      (USD today). Falling back beats being unable to pay at all, but it
     *      is a fallback, not a decision: those shops are the ones most likely
     *      to need an override.
     *
     * The shop cannot set step 1 itself. The ladders are not at parity across
     * currencies, so self-service would be an arbitrage lever that moves with
     * FX rather than a preference.
     */
    public static function for(Subscription|Tenant|null $source): string
    {
        $subscription = $source instanceof Subscription ? $source : $source?->subscription;
        $tenant = $source instanceof Tenant ? $source : $subscription?->tenant;

        foreach ([$subscription?->billing_currency, $tenant?->currency] as $candidate) {
            $currency = strtoupper((string) $candidate);

            if (array_key_exists($currency, (array) config('billing.currencies'))) {
                return $currency;
            }
        }

        return (string) config('billing.default_currency');
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys((array) config('billing.currencies'));
    }

    public static function amountFor(string $currency, string $plan): float
    {
        return (float) config("billing.currencies.{$currency}.plans.{$plan}.amount");
    }

    /**
     * Null means that plan cannot be sold by card in that currency. For MMK
     * that is structural — Stripe does not support it — not a missing setting.
     */
    public static function stripePriceFor(string $currency, string $plan): ?string
    {
        return config("billing.currencies.{$currency}.plans.{$plan}.stripe_price_id");
    }

    /**
     * Whether Stripe can process this currency at all — a fact about the
     * provider rather than about how this deployment is configured. Absent
     * entries default to false: a currency nobody has confirmed Stripe
     * handles should not have card offered against it.
     */
    public static function stripeSupports(string $currency): bool
    {
        return (bool) config("billing.currencies.{$currency}.stripe_supported", false);
    }

    /**
     * The PLATFORM's own receiving account for this currency — where a shop
     * transfers its subscription fee. Not the shop's bank details, which live
     * on tenant_payment_methods and face the shop's own customers.
     *
     * @return array{bank_name: ?string, account_name: ?string, account_number: ?string, instructions: ?string}
     */
    public static function receivingAccount(string $currency): array
    {
        return (array) config("billing.currencies.{$currency}.manual", []);
    }

    /**
     * Both halves are required. An account with no price is a transfer of an
     * unknown amount that a human then has to guess at; a price with no
     * account is a bill nobody can pay.
     */
    public static function canReceiveTransfer(string $currency, string $plan): bool
    {
        return filled(self::receivingAccount($currency)['account_number'] ?? null)
            && self::amountFor($currency, $plan) > 0;
    }
}
