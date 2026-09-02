<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Scoped-unique on name, the same shape as the sku rule in
     * StoreProductVariantRequest: uniqueness is per shop, so two different
     * tenants can both have a "Royal Express" while neither can have two.
     * The DB unique index behind it is the real backstop; this exists to
     * turn a 500 into a 422 with a field name attached.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('delivery_providers', 'name')->where(
                    fn ($query) => $query->where('tenant_id', app('tenant')->id)
                ),
            ],
            // Same max:32 as customers.phone and tenants.business_phone.
            'phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
