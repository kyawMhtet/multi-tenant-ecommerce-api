<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Services\Pricing\DiscountType;
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
            // A promotion on this variant. Null type = no discount, which is
            // also how one is cleared. Percent is capped at 100 — anything
            // above it is a typo, since a discount can only ever reach free.
            // A fixed amount is NOT capped against selling_price: the price is
            // mutable, so the clamp in DiscountType::amountOff() is the real
            // guard rather than a check that goes stale.
            'variant.discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'variant.discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:variant.discount_type', Rule::when(
                $this->input('variant.discount_type') === DiscountType::Percent->value,
                ['max:100'],
            )],
            // Both ends optional: no start means live now, no end means until
            // the shop withdraws it.
            'variant.discount_starts_at' => ['nullable', 'date'],
            'variant.discount_ends_at' => ['nullable', 'date', Rule::when(
                filled($this->input('variant.discount_starts_at')),
                ['after:variant.discount_starts_at'],
            )],
            'variant.track_stock' => ['sometimes', 'boolean'],
            'variant.allow_preorder' => ['sometimes', 'boolean'],
            'variant.preorder_lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'variant.preorder_deposit_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'variant.current_stock' => ['sometimes', 'numeric', 'min:0'],
            'variant.low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'variant.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
