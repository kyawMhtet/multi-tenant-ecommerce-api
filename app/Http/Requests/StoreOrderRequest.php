<?php

namespace App\Http\Requests;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                // ProductVariant::find() applies the tenant global scope, so
                // a variant id belonging to another tenant fails validation
                // here rather than being rejected only deeper in the service.
                if (! ProductVariant::find($value)) {
                    $fail('One or more items are invalid.');
                }
            }],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'payment_method' => ['required', Rule::in(['cash'])],
        ];
    }
}
