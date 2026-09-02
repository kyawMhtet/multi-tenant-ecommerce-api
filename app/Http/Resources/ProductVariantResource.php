<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'barcode' => $this->barcode,
            'variant_name' => $this->variant_name,
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            'buying_price' => $this->buying_price,
            'selling_price' => $this->selling_price,
            'track_stock' => $this->track_stock,
            'allow_preorder' => $this->allow_preorder,
            'preorder_lead_time_days' => $this->preorder_lead_time_days,
            'preorder_requires_prepayment' => $this->preorder_requires_prepayment,
            // Can be negative on a preorder variant, and that is data, not
            // a bug: it's the number of units already sold and still owed.
            'current_stock' => $this->current_stock,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
