<?php

namespace App\Services\Billing;

/**
 * A code constant rather than a table, for the same reason
 * PaymentMethodCatalog is: each entry implies behaviour the app has to
 * implement — an enforcement point for every limit, a gate for every feature —
 * which a row someone inserts cannot supply. A plan invented in a database
 * would unlock nothing.
 *
 * Contrast delivery_providers, which IS a table: a courier teaches the app
 * nothing, so its set is open. A plan teaches it what to refuse.
 *
 * Prices and Stripe price ids are deliberately NOT here — see config/billing.php.
 * Behaviour is code; pricing is deployment.
 */
class PlanCatalog
{
    /**
     * `null` for a limit means unlimited, never 0. Zero is a real answer to
     * "how many may you create" and must stay expressible.
     */
    public const PLANS = [
        'starter' => [
            'label' => 'Starter',
            'limits' => [
                'products' => 50,
                // No enforcement point yet: this app has no staff-management
                // endpoint, so no request can create a second user. Listed
                // here so the number is decided in one place when that
                // endpoint arrives, rather than invented at the call site.
                'staff' => 3,
            ],
            'features' => [],
        ],
        'pro' => [
            'label' => 'Pro',
            'limits' => [
                'products' => null,
                'staff' => null,
            ],
            'features' => [
                PlanFeature::CardPayments,
                PlanFeature::ProfitReports,
                PlanFeature::Preorder,
            ],
        ],
    ];

    /**
     * What a shop falls back to when it has no usable subscription at all:
     * trial finished without payment, subscription cancelled, or a row that
     * somehow names a plan this catalogue no longer defines.
     *
     * The cheapest plan, never the most generous — an unknown state must not
     * be worth more than a paid one, or "unknown" becomes the best plan to be
     * in. Note this is the entitlement floor, not free access: a shop in this
     * state is also read-only (see Subscription::isReadOnly()), so the limits
     * below are what it keeps if it starts paying, not what it enjoys while
     * it doesn't.
     */
    public const FALLBACK = 'starter';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PLANS);
    }

    /**
     * Where a plan sits on the ladder, from the order of self::PLANS.
     *
     * Declaration order IS the ladder — deliberately, rather than comparing
     * prices. Prices are per currency and could in principle cross over
     * between markets, whereas "Pro is above Starter" is true everywhere. Keep
     * PLANS ordered cheapest-first, and note that inserting a tier in the
     * middle changes every comparison, which is correct but worth knowing.
     *
     * An unknown plan ranks below everything, so a stale plan string can only
     * ever read as a downgrade — the same fail-quiet direction as FALLBACK.
     */
    public static function rank(string $plan): int
    {
        $position = array_search($plan, self::codes(), true);

        return $position === false ? -1 : $position;
    }

    public static function isUpgrade(string $from, string $to): bool
    {
        return self::rank($to) > self::rank($from);
    }

    public static function isDowngrade(string $from, string $to): bool
    {
        return self::rank($to) < self::rank($from);
    }

    public static function exists(string $plan): bool
    {
        return array_key_exists($plan, self::PLANS);
    }

    public static function labelFor(string $plan): string
    {
        return self::PLANS[$plan]['label'] ?? ucfirst($plan);
    }

    /**
     * Whether a plan unlocks a feature. Unknown plans resolve to the fallback
     * rather than throwing: this is called from the middle of ordinary
     * requests, and a plan string that drifted out of the catalogue (a
     * renamed tier, a half-finished migration) should downgrade the shop's
     * abilities, not 500 every page it loads.
     */
    public static function allows(string $plan, PlanFeature $feature): bool
    {
        return in_array($feature, self::resolve($plan)['features'], true);
    }

    /**
     * The ceiling for a countable resource, or null for unlimited.
     *
     * An unknown limit name returns null (unlimited) on purpose, and this is
     * the one place in the billing code that fails OPEN rather than closed.
     * The alternative is worse: a typo'd limit name would silently cap every
     * shop at zero and block creation across the platform. A missing limit
     * should let work continue; PlanFeature exists as an enum precisely so
     * the same mistake can't be made on the features side, where failing open
     * would give away paid capability.
     */
    public static function limitFor(string $plan, string $limit): ?int
    {
        return self::resolve($plan)['limits'][$limit] ?? null;
    }

    /** @return array{label: string, limits: array<string, int|null>, features: list<PlanFeature>} */
    private static function resolve(string $plan): array
    {
        return self::PLANS[$plan] ?? self::PLANS[self::FALLBACK];
    }
}
