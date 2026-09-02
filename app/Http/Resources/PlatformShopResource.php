<?php

namespace App\Http\Resources;

use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Tenant
 *
 * A shop as platform staff see it — owner contact, billing state, suspension.
 * Staff-facing only, and a separate class from TenantResource for the same
 * reason PlatformInvoiceResource is separate: this one exposes the owner's
 * email and phone and the shop's billing internals. Two resources cannot leak
 * into each other; one resource with a conditional can.
 *
 * Serves both the directory and the detail view. The extra fields on detail
 * are gated with whenLoaded/whenCounted rather than split into a second class,
 * because they are the same shop — a list row and a detail page disagreeing
 * about a shop's plan would be worse than a slightly conditional resource.
 */
class PlatformShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->whenLoaded('subscription');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_name' => $this->owner_name,
            'owner_email' => $this->owner_email,
            'owner_phone' => $this->owner_phone,
            // What the shop SELLS in. What it pays US in is on the
            // subscription below and is not necessarily the same.
            'selling_currency' => $this->currency,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'is_suspended' => $this->isSuspended(),
            'suspended_at' => $this->suspended_at,
            'suspension_reason' => $this->suspension_reason,
            'created_at' => $this->created_at,

            'subscription' => $this->when(
                $this->relationLoaded('subscription'),
                fn () => $this->subscription === null ? null : [
                    'id' => $this->subscription->id,
                    // effectivePlan(), never the raw column: a shop with a
                    // scheduled downgrade is still on the plan it paid for.
                    'plan' => $this->subscription->effectivePlan(),
                    'plan_label' => PlanCatalog::labelFor($this->subscription->effectivePlan()),
                    'status' => $this->subscription->status,
                    'rail' => $this->subscription->gateway,
                    'billing_currency' => BillingCurrency::for($this->subscription),
                    'is_on_trial' => $this->subscription->isOnTrial(),
                    'is_in_grace' => $this->subscription->isInGrace(),
                    'is_read_only' => $this->subscription->isReadOnly(),
                    'current_period_ends_at' => $this->subscription->current_period_ends_at,
                    'pending_plan' => $this->subscription->pending_plan,
                    'pending_plan_starts_at' => $this->subscription->pending_plan_starts_at,
                ],
            ),

            // Detail only. The quickest way to tell a real business from an
            // abandoned signup, which is the first thing worth knowing when a
            // shop turns up in the support queue.
            'products_count' => $this->whenCounted('products'),
            'orders_count' => $this->whenCounted('orders'),
            'invoices' => PlatformInvoiceResource::collection(
                $this->whenLoaded('subscriptionInvoices')
            ),
        ];
    }
}
