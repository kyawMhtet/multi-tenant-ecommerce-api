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
            'current_stock' => $this->current_stock,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
        ];
    }
}
