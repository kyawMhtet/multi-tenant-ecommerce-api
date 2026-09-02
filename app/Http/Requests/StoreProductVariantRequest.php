<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Same rules as StoreProductRequest's nested 'variant.*' block, unnested. */
    public function rules(): array
    {
        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('product_variants', 'sku')->where(
                    fn ($query) => $query->where('tenant_id', app('tenant')->id)
                ),
            ],
            'barcode' => ['nullable', 'string', 'max:255'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'buying_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            // Sells past zero, turning current_stock negative — the backlog.
            // Capped at a year: longer is far more likely a typo than a promise.
            'allow_preorder' => ['sometimes', 'boolean'],
            'preorder_lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Refuses COD while this item is on preorder; ignored once it's
            // back in stock, since the line stops being a preorder.
            'preorder_requires_prepayment' => ['sometimes', 'boolean'],
            'current_stock' => ['sometimes', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // For when this variant looks visually different (a colour).
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
