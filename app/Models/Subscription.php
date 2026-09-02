<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanFeature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop's billing relationship with this platform — one row per tenant.
 *
 * Tenant-scoped like everything else, so a shop can only ever read its own.
 * The billing webhook is the exception and bypasses the scope precisely, for
 * the same reason WebhookProcessor does: a gateway callback carries no token
 * and no X-Tenant-Slug, so the tenant is derived from whichever provider
 * reference resolves.
 *
 * Two orthogonal axes live here, and keeping them separate is the whole
 * design:
 *
 *   PLAN      — what this shop is entitled to (features, limits)
 *   ACCESS    — whether it may currently write at all
 *
 * A lapsed Pro shop stays on Pro and goes read-only. It does NOT silently
 * become a Starter shop: quietly reducing what someone bought, while still
 * showing them as a Pro customer, is the kind of half-state that produces
 * "why did my report disappear" tickets nobody can explain. Read-only is a
 * state the shop can see and act on.
 */
#[Fillable([
    'plan', 'billing_currency', 'pending_plan', 'pending_plan_starts_at', 'status', 'gateway',
    'external_customer_ref', 'external_subscription_ref',
    'trial_ends_at', 'current_period_ends_at', 'cancel_at_period_end', 'cancelled_at', 'meta',
])]
class Subscription extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'pending_plan_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * The plan actually enforced, and the ONLY thing anything should read.
     *
     * `plan` is the plan as of the current period; `pending_plan` is a
     * downgrade already agreed but not yet due. This method is what makes the
     * switch happen on time without a scheduler — comparing the date here
     * beats a nightly job that would be a second source of truth for
     * something the dates already answer.
     *
     * Falls back to the cheapest plan only when the stored plan has left the
     * catalogue — a tier renamed or removed — never as a consequence of
     * non-payment. Non-payment is expressed by isReadOnly(), not by silently
     * shrinking what the shop is on.
     */
    public function effectivePlan(): string
    {
        $plan = $this->pendingPlanIsDue() ? $this->pending_plan : $this->plan;

        return PlanCatalog::exists((string) $plan) ? (string) $plan : PlanCatalog::FALLBACK;
    }

    /**
     * A downgrade the shop has agreed to that hasn't arrived yet. Worth
     * exposing so the billing screen can say "switching to Starter on 14 Oct"
     * rather than the shop discovering it on the day.
     */
    public function hasScheduledPlanChange(): bool
    {
        return $this->pending_plan !== null && ! $this->pendingPlanIsDue();
    }

    private function pendingPlanIsDue(): bool
    {
        return $this->pending_plan !== null
            && $this->pending_plan_starts_at !== null
            && $this->pending_plan_starts_at->isPast();
    }

    public function allows(PlanFeature $feature): bool
    {
        return PlanCatalog::allows($this->effectivePlan(), $feature);
    }

    /** null means unlimited. */
    public function limitFor(string $limit): ?int
    {
        return PlanCatalog::limitFor($this->effectivePlan(), $limit);
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * When paid (or trial) access runs out, ignoring grace. Null means there
     * is no live entitlement at all.
     */
    public function accessEndsAt(): ?Carbon
    {
        return $this->status === 'trialing'
            ? $this->trial_ends_at
            : $this->current_period_ends_at;
    }

    /**
     * Grace is DERIVED, never stored, so it cannot disagree with the period
     * end it follows.
     *
     * The manual rail gets the longer window because its failure modes are
     * slower: a bank transfer has to be sent, clear, and then be checked by a
     * human here. A card either works or it doesn't, and the shop finds out
     * the same day.
     *
     * A deliberate cancellation gets NO grace. Grace absorbs payment
     * friction; someone who chose to leave has no friction to absorb, and
     * extending their access would just be a week of free service.
     */
    public function graceEndsAt(): ?Carbon
    {
        $endsAt = $this->accessEndsAt();

        if ($endsAt === null || $this->status === 'cancelled') {
            return $endsAt;
        }

        $days = $this->gateway === 'manual'
            ? (int) config('billing.manual_grace_days')
            : (int) config('billing.grace_days');

        return $endsAt->copy()->addDays($days);
    }

    /**
     * The single question every write-side gate asks.
     *
     * A null end date means no entitlement was ever established and the
     * answer is no — deliberately, because the bug this replaces was exactly
     * that: AuthService::register() set no trial end, so `trial` with a null
     * date meant unlimited free access forever. A missing date must read as
     * "expired", not "eternal".
     */
    public function allowsWrites(): bool
    {
        return $this->graceEndsAt()?->isFuture() ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! $this->allowsWrites();
    }

    /**
     * Past the paid period but still inside grace — the state where the shop
     * works normally and the admin app should be showing a warning. Worth
     * naming separately from allowsWrites() so the UI can distinguish "you
     * are about to lose access" from "everything is fine".
     */
    public function isInGrace(): bool
    {
        return $this->accessEndsAt()?->isPast() === true && $this->allowsWrites();
    }
}
