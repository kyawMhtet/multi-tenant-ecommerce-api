<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeliveryProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same fields as StoreDeliveryProviderRequest, all optional for a
     * partial update, with the uniqueness check ignoring this row so
     * saving an unchanged name isn't rejected as a duplicate of itself.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('delivery_providers', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', app('tenant')->id))
                    ->ignore($this->route('provider')),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
