<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing: never buying_price/unit_cost, and never the raw
 * current_stock count — see stockStatus().
 */
class StorefrontProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stockStatus = $this->stockStatus();

        return [
            'slug' => $this->slug,
            'variant_name' => $this->variant_name,
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            'selling_price' => $this->selling_price,
            'stock_status' => $stockStatus,
            // Only when actually on preorder, so a client can't render "ships
            // in 2 weeks" against something on the shelf. The customer must
            // learn about the wait BEFORE paying — that's the whole feature.
            'preorder_lead_time_days' => $stockStatus === 'preorder'
                ? $this->preorder_lead_time_days
                : null,
            // Lets checkout hide COD for a cart containing this item. Advisory
            // only — OrderService enforces the same rule server-side.
            'preorder_requires_prepayment' => $stockStatus === 'preorder'
                ? (bool) $this->preorder_requires_prepayment
                : null,
            // Empty when the variant has no photos of its own; falling back to
            // the product's is a frontend display choice.
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }

    /**
     * Coarse, not the exact number: an exact count lets a competitor track a
     * shop's sell-through rate, or a bot snipe the last unit. The tradeoff is
     * real (a wholesale buyer gets no answer) but deliberate.
     */
    private function stockStatus(): string
    {
        if (! $this->track_stock) {
            return 'in_stock';
        }

        if ($this->current_stock <= 0) {
            // Checked only once stock has run out — that ordering is what makes
            // allow_preorder a permission rather than a mode. Without it, a shop
            // leaving the flag on would advertise a wait on everything it had.
            return $this->allow_preorder ? 'preorder' : 'out_of_stock';
        }

        if ($this->low_stock_threshold !== null && $this->current_stock <= $this->low_stock_threshold) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
