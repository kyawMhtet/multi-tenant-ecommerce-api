<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same field set as StoreProductVariantRequest, all optional for a
     * partial update. current_stock is deliberately absent — every stock
     * change must go through StockService and its ledger (stock_movements),
     * never a plain field update (see CLAUDE.md's Stock and money section).
     */
    public function rules(): array
    {
        return [
            'sku' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('product_variants', 'sku')
                    ->where(fn ($query) => $query->where('tenant_id', app('tenant')->id))
                    ->ignore($this->route('variant')),
            ],
            'barcode' => ['nullable', 'string', 'max:255'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'buying_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Same append-never-replace / fold-removal-into-update
            // convention as UpdateProductRequest, scoped to this variant's
            // own images instead of the product's general ones.
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer', function ($attribute, $value, $fail) {
                if (! $this->route('variant')->images()->whereKey($value)->exists()) {
                    $fail('One or more images to remove are invalid.');
                }
            }],
        ];
    }
}
