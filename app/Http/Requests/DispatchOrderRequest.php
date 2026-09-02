<?php

namespace App\Http\Requests;

use App\Models\DeliveryProvider;
use Illuminate\Foundation\Http\FormRequest;

class DispatchOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A tenant-scoped lookup, never `exists:delivery_providers,id` — the plain
     * rule would match across tenants.
     *
     * tracking_number is optional: a shop's own rider has none, and requiring
     * one would either block the dispatch or get "-" typed in.
     */
    public function rules(): array
    {
        return [
            'delivery_provider_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                if (! DeliveryProvider::whereKey($value)->exists()) {
                    $fail('The selected delivery provider is not available.');
                }
            }],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
