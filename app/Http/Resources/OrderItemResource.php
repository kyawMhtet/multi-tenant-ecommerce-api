<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Receipt-facing: no unit_cost here, same reasoning as ProductResource's
 * public/admin split — a receipt is something a customer may see, so
 * margin data has no business being on it even in an otherwise
 * admin-authenticated POS flow.
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            // variant_name alone is often null (a simple product's single
            // variant has no name), so sku/attributes are usually what
            // actually identifies which item was sold.
            'sku' => $this->sku,
            'attributes' => $this->attributes,
            'quantity' => $this->quantity,
            // Snapshotted at sale time, so a receipt reprinted months later
            // still shows what this customer was told when they paid, even
            // if the variant is back in stock or the lead time has changed.
            'is_preorder' => $this->is_preorder,
            'preorder_lead_time_days' => $this->preorder_lead_time_days,
            // The list price, with what came off it beside — a receipt has to
            // show the customer the saving, not just a smaller number.
            // unit_price x quantity - discount_amount = line_total.
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'line_total' => $this->line_total,
        ];
    }
}
