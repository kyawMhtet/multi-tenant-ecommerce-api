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
        $onSale = $this->discountActive();

        return [
            'slug' => $this->slug,
            'variant_name' => $this->variant_name,
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            // Always the LIST price, discounted or not — it's the "was"
            // figure a sale price is struck through against. A client that
            // knows nothing about sale_price therefore shows the higher
            // number and the customer is charged less, which is the safe
            // direction for an old client to be wrong in.
            'selling_price' => $this->selling_price,
            // Null when nothing is running, so "is this on sale" is one
            // check rather than a date comparison the client has to get
            // right. Advisory, like preorder_deposit_percent: OrderService
            // prices the cart server-side and this never reaches it.
            'sale_price' => $onSale ? number_format($this->effectivePrice(), 2, '.', '') : null,
            // The badge figure, derived even for a fixed discount so the
            // storefront renders one badge instead of branching on type.
            'discount_percent' => $onSale ? $this->discountPercent() : null,
            'stock_status' => $stockStatus,
            // Only when actually on preorder, so a client can't render "ships
            // in 2 weeks" against something on the shelf. The customer must
            // learn about the wait BEFORE paying — that's the whole feature.
            'preorder_lead_time_days' => $stockStatus === 'preorder'
                ? $this->preorder_lead_time_days
                : null,
            // What the customer must pay up front, as a percentage. Lets
            // checkout hide COD and show "50% deposit" BEFORE they commit —
            // finding out at the payment step that half is due is the same
            // surprise as finding out about the wait after paying.
            //
            // Withheld unless the status is preorder, same as the lead time: a
            // variant with stock in hand ships today and asks for no deposit,
            // whatever the shop has set for when it runs out.
            //
            // Advisory only — OrderService enforces the real rule server-side.
            'preorder_deposit_percent' => $stockStatus === 'preorder'
                ? (int) $this->preorder_deposit_percent
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
