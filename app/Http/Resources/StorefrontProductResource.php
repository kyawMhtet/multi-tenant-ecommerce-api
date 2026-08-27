<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public counterpart to ProductResource — no category_id, no
 * is_active flag (irrelevant to a customer), and variants go through
 * StorefrontProductVariantResource, not ProductVariantResource, so cost
 * and exact stock figures never reach this response even if a future
 * change accidentally nests the wrong resource elsewhere.
 */
class StorefrontProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => StorefrontProductVariantResource::collection($this->whenLoaded('variants')),
            // Embedded rather than left to a second call: this page is
            // reached by a pasted link, so the client has no slug to send
            // as X-Tenant-Slug and literally cannot call /public/shop. Reusing
            // the same resource keeps exactly one definition of what's public
            // about a shop, so the two storefront surfaces can't drift.
            'shop' => new PublicShopResource($this->whenLoaded('tenant')),
        ];
    }
}
