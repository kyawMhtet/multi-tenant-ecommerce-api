<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing: never buying_price/unit_cost (same cost-visibility
 * split CLAUDE.md establishes for receipts), and never the raw
 * current_stock count — see stockStatus() for why.
 */
class StorefrontProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'variant_name' => $this->variant_name,
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            'selling_price' => $this->selling_price,
            'stock_status' => $this->stockStatus(),
        ];
    }

    /**
     * A coarse status, not the exact number: an exact count lets a
     * competitor track a shop's sell-through rate over time, or lets a
     * bot time a snipe on the last unit. "In stock / low stock / out of
     * stock" gives a customer enough to decide whether to buy now,
     * without turning the endpoint into a live inventory feed. The
     * tradeoff is real — a wholesale buyer asking "do you have 50 in
     * stock?" gets no real answer from this — but that's a deliberate
     * future exception (e.g. a quote-request flow), not the default.
     */
    private function stockStatus(): string
    {
        if (! $this->track_stock) {
            return 'in_stock';
        }

        if ($this->current_stock <= 0) {
            return 'out_of_stock';
        }

        if ($this->low_stock_threshold !== null && $this->current_stock <= $this->low_stock_threshold) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
