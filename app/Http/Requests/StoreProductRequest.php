<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                // Category::find() applies the tenant global scope, so this
                // rejects a category_id belonging to another tenant without
                // ever comparing tenant_id by hand.
                if ($value !== null && ! Category::find($value)) {
                    $fail('The selected category does not exist.');
                }
            }],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],

            // A simple product still gets exactly one variant, created inline
            // here so the caller never has to think about variants explicitly.
            'variant' => ['required', 'array'],
            'variant.sku' => [
                'required', 'string', 'max:255',
                Rule::unique('product_variants', 'sku')->where(
                    fn ($query) => $query->where('tenant_id', app('tenant')->id)
                ),
            ],
            'variant.barcode' => ['nullable', 'string', 'max:255'],
            'variant.variant_name' => ['nullable', 'string', 'max:255'],
            'variant.attributes' => ['nullable', 'array'],
            'variant.unit' => ['sometimes', 'string', 'max:50'],
            'variant.buying_price' => ['required', 'numeric', 'min:0'],
            'variant.selling_price' => ['required', 'numeric', 'min:0'],
            'variant.track_stock' => ['sometimes', 'boolean'],
            'variant.current_stock' => ['sometimes', 'numeric', 'min:0'],
            'variant.low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'variant.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
