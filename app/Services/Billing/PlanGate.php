<?php

namespace App\Services\Billing;

use App\Exceptions\FeatureNotOnPlanException;
use App\Exceptions\PlanLimitExceededException;
use App\Exceptions\SubscriptionInactiveException;
use App\Models\Subscription;

/**
 * The one place that answers "may this shop do this". Middleware and services
 * both ask it rather than reading Subscription directly, so the rules for
 * missing tenants and missing subscriptions are decided once instead of in
 * every caller.
 *
 * Resolved per call rather than cached on the instance: PlanGate is a
 * singleton-shaped service in a container that outlives a single tenant in
 * tests and would in a queue worker, and a cached subscription is a cached
 * TENANT — precisely the cross-request leak CLAUDE.md already warns about
 * for the 'tenant' binding.
 */
class PlanGate
{
    /**
     * Null when no tenant is bound at all.
     *
     * That case is NOT an error and NOT a refusal: it means no shop is
     * acting. Webhooks, console commands and scheduled jobs all run without a
     * tenant, and they are the platform operating on its own data rather than
     * a shop spending its own allowance. TenantScope::apply() takes exactly
     * the same position — no tenant bound, no filter — and disagreeing with
     * it here would mean two different answers to "whose request is this".
     *
     * Every user-facing route sits behind the 'tenant' middleware, so a real
     * request can never reach a gate with nothing bound.
     */
    public function subscription(): ?Subscription
    {
        if (! app()->bound('tenant')) {
            return null;
        }

        return app('tenant')->subscription;
    }

    /**
     * A tenant with no subscription row fails CLOSED — fallback plan, no
     * writes. It is not a state registration can produce (AuthService starts
     * a trial in the same transaction), so reaching it means data was created
     * around the app. Treating that as unlimited access would make "delete
     * your subscription row" the cheapest upgrade available.
     */
    public function plan(): string
    {
        return $this->subscription()?->effectivePlan() ?? PlanCatalog::FALLBACK;
    }

    public function allows(PlanFeature $feature): bool
    {
        $subscription = $this->subscription();

        // No tenant bound: system context, nothing to gate.
        if ($subscription === null && ! app()->bound('tenant')) {
            return true;
        }

        return $subscription?->allows($feature) ?? false;
    }

    public function allowsWrites(): bool
    {
        $subscription = $this->subscription();

        if ($subscription === null && ! app()->bound('tenant')) {
            return true;
        }

        return $subscription?->allowsWrites() ?? false;
    }

    public function ensureFeature(PlanFeature $feature): void
    {
        if (! $this->allows($feature)) {
            throw new FeatureNotOnPlanException($feature, $this->plan());
        }
    }

    public function ensureWritable(): void
    {
        if (! $this->allowsWrites()) {
            throw new SubscriptionInactiveException;
        }
    }

    /**
     * `$current` is passed in rather than counted here, because only the
     * caller knows what it is counting and under which scope — and because
     * the count has to happen inside the caller's transaction to mean
     * anything under concurrency.
     *
     * Uses >= : this is asked BEFORE the row is created, so a shop at exactly
     * the limit must be refused rather than allowed to reach limit + 1.
     */
    public function ensureWithin(string $limit, int $current): void
    {
        $maximum = PlanCatalog::limitFor($this->plan(), $limit);

        // null is unlimited. Note this is reached only when a tenant IS
        // bound, so the system-context case never lands here.
        if ($maximum === null || $current < $maximum) {
            return;
        }

        throw new PlanLimitExceededException($limit, $maximum, $current, $this->plan());
    }
}
