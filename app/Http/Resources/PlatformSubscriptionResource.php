<?php

namespace App\Http\Resources;

use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Subscription
 *
 * The staff-facing view of a subscription. Like PlatformInvoiceResource, a
 * separate class from SubscriptionResource rather than a flag on it, because
 * this one names the SHOP and must never reach a shop.
 *
 * It reports the override and the effective answer SEPARATELY. That
 * distinction is the whole point of the field: null means "follows the shop's
 * selling currency", which is right for almost every shop, and a value means
 * someone deliberately decided otherwise.
 */
class PlatformSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop' => [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
                'slug' => $this->tenant?->slug,
                // What the shop SELLS in — not what it pays us in.
                'selling_currency' => $this->tenant?->currency,
            ],
            'plan' => $this->effectivePlan(),
            'plan_label' => PlanCatalog::labelFor($this->effectivePlan()),
            'status' => $this->status,
            'rail' => $this->gateway,
            // null = following the shop's selling currency.
            'billing_currency_override' => $this->billing_currency,
            'billing_currency' => BillingCurrency::for($this->resource),
            'current_period_ends_at' => $this->current_period_ends_at,
        ];
    }
}
