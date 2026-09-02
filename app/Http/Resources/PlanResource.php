<?php

namespace App\Http\Resources;

use App\Services\Billing\BillingCurrency;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plan code plus what the controller worked out about it, not a
 * model — plans are a code constant, so there is no row to serialise.
 *
 * Limits and features come from PlanCatalog (behaviour) and the price from
 * config (deployment), which is the same split the two live on. `rails` is
 * passed in rather than looked up here, so the admin app never renders a
 * "pay by card" button for a plan whose Stripe price id is unset.
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $code = $this['code'];

        return [
            'code' => $code,
            'label' => PlanCatalog::labelFor($code),
            // Priced in the SHOP's own currency, not one platform currency:
            // a Yangon shop is quoted Kyat and pays into a Myanmar account.
            'amount' => BillingCurrency::amountFor($this['currency'], $code),
            'currency' => $this['currency'],
            'limits' => PlanCatalog::PLANS[$code]['limits'],
            'features' => array_map(
                fn (PlanFeature $feature) => $feature->value,
                PlanCatalog::PLANS[$code]['features'],
            ),
            // Which buttons to render.
            'rails' => $this['rails'],
            // ...and what to say about the ones that are missing. A rail the
            // shop's currency can never support needs different words from one
            // we simply haven't finished setting up.
            'rail_status' => $this['rail_status'],
            'is_current' => $this['is_current'],
        ];
    }
}
