<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing only: exposes buying_price/current_stock via the nested
 * variant. There's no separate public/storefront resource yet because
 * there's no storefront route to serve it — a StorefrontProductResource
 * (name/description/selling_price only, no buying_price or stock counts)
 * needs to exist before any unauthenticated route returns product data.
 * Never reuse this resource for that route when it's built.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'is_active' => $this->is_active,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
