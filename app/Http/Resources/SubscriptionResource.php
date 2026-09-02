<?php

namespace App\Http\Resources;

use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Subscription
 *
 * Exposes the DERIVED answers (is_read_only, is_in_grace, grace_ends_at)
 * rather than making the admin app recompute them from dates and a status
 * string. Two implementations of the same rule is how a client ends up
 * showing "active" over an account the API is already refusing.
 *
 * No provider identifiers are published. cus_.../sub_... are internal
 * plumbing, useless to the shop, and the fewer places they appear the less
 * chance one gets confused with tenants.stripe_account_id.
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->effectivePlan(),
            'plan_label' => PlanCatalog::labelFor($this->effectivePlan()),
            // A downgrade already agreed but not yet due. The shop keeps the
            // plan it paid for until this date, then drops. Surfaced so the
            // screen can say so rather than the shop finding out on the day.
            'pending_plan' => $this->when($this->hasScheduledPlanChange(), $this->pending_plan),
            'pending_plan_label' => $this->when(
                $this->hasScheduledPlanChange(),
                fn () => PlanCatalog::labelFor((string) $this->pending_plan),
            ),
            'pending_plan_starts_at' => $this->when(
                $this->hasScheduledPlanChange(),
                $this->pending_plan_starts_at,
            ),
            'status' => $this->status,
            // 'stripe', 'manual', or null while still on trial with no rail
            // chosen — a trial has no payment method yet, and saying 'stripe'
            // would assert a card that may never exist.
            'rail' => $this->gateway,
            'is_on_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at,
            'current_period_ends_at' => $this->current_period_ends_at,
            'access_ends_at' => $this->accessEndsAt(),
            // Past the paid period but still working. The admin app should be
            // warning loudly here — this is the window where the shop can fix
            // it before anything stops.
            'is_in_grace' => $this->isInGrace(),
            'grace_ends_at' => $this->graceEndsAt(),
            'is_read_only' => $this->isReadOnly(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'cancelled_at' => $this->cancelled_at,
        ];
    }
}
