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

    /**
     * Same field set and rules as StoreProductRequest's nested 'variant.*'
     * block, just unnested — this request validates one variant directly,
     * not a variant embedded in a product-creation payload.
     */
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
            'current_stock' => ['sometimes', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Optional, same limits as a product's own images — most
            // useful when this variant looks visually different (a color,
            // a pattern) from the product's general photos.
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
