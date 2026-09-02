<?php

namespace App\Http\Requests;

use App\Services\Billing\BillingRailManager;
use App\Services\Billing\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Both fields are validated against catalogues rather than accepted as free
 * text. There is no amount field and never will be: a money figure a client
 * can send is a money figure a client can set to zero — the same rule that
 * keeps tenant_id, unit_price and delivery_fee out of request bodies. What a
 * plan costs is resolved server-side from config.
 */
class StartSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::in(PlanCatalog::codes())],
            'rail' => ['required', 'string', Rule::in(app(BillingRailManager::class)->names())],
        ];
    }
}
