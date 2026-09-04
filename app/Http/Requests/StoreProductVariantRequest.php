<?php

namespace App\Http\Requests;

use App\Services\Pricing\DiscountType;
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
            // A promotion on this variant. Null type = no discount, which is
            // also how one is cleared. Percent is capped at 100 — anything
            // above it is a typo, since a discount can only ever reach free.
            // A fixed amount is NOT capped against selling_price: the price is
            // mutable, so the clamp in DiscountType::amountOff() is the real
            // guard rather than a check that goes stale.
            'discount_type' => ['nullable', Rule::in(DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:discount_type', Rule::when(
                $this->input('discount_type') === DiscountType::Percent->value,
                ['max:100'],
            )],
            // Both ends optional: no start means live now, no end means until
            // the shop withdraws it.
            'discount_starts_at' => ['nullable', 'date'],
            'discount_ends_at' => ['nullable', 'date', Rule::when(
                filled($this->input('discount_starts_at')),
                ['after:discount_starts_at'],
            )],
            'track_stock' => ['sometimes', 'boolean'],
            // Sells past zero, turning current_stock negative — the backlog.
            // Capped at a year: longer is far more likely a typo than a promise.
            'allow_preorder' => ['sometimes', 'boolean'],
            'preorder_lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Refuses COD while this item is on preorder; ignored once it's
            // back in stock, since the line stops being a preorder.
            'preorder_deposit_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'current_stock' => ['sometimes', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // For when this variant looks visually different (a colour).
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
