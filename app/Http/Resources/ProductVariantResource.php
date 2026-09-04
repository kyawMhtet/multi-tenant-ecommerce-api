<?php

namespace App\Http\Resources;

use App\Services\Tenants\ShopRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seesCost = ShopRole::atLeast($request->user()?->role, ShopRole::Manager);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'barcode' => $this->barcode,
            'variant_name' => $this->variant_name,
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            // Withheld from a cashier: margin is the shop's business, and
            // the product list is not forbidden to them, only this field.
            'buying_price' => $this->when($seesCost, fn () => $this->buying_price),
            'selling_price' => $this->selling_price,
            // The promotion as CONFIGURED, plus what it currently comes to.
            // Both, because the admin form needs the settings back to edit
            // them, while the list needs the live figure — and recomputing
            // that figure in the frontend is how the two ends start
            // disagreeing about what a customer is being charged.
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_starts_at' => $this->discount_starts_at,
            'discount_ends_at' => $this->discount_ends_at,
            // Derived from the window, so a scheduled promotion that hasn't
            // started reads false here while its dates still show.
            'discount_active' => $this->discountActive(),
            // Formatted like every other money field on this resource, which
            // the decimal:2 casts render as strings — a bare float here would
            // hand the client 850 where selling_price is "1000.00".
            'effective_price' => number_format($this->effectivePrice(), 2, '.', ''),
            'track_stock' => $this->track_stock,
            'allow_preorder' => $this->allow_preorder,
            'preorder_lead_time_days' => $this->preorder_lead_time_days,
            // 0 = no deposit, 100 = pay in full before we order it, and
            // anything between is the half-prepaid arrangement shops here
            // actually advertise.
            'preorder_deposit_percent' => $this->preorder_deposit_percent,
            // Can be negative on a preorder variant, and that is data, not
            // a bug: it's the number of units already sold and still owed.
            'current_stock' => $this->current_stock,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
