<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array from DashboardService::getSummary(), not an Eloquent
 * model — the whole point of this resource is assembling several narrow,
 * purpose-built slices into one response, not exposing a single model's
 * attributes. Deliberately narrower than OrderResource/ProductVariantResource
 * for the nested lists: no buying_price/unit_cost, same rule as every other
 * resource in this app, and no raw fields beyond what a dashboard card or
 * list row actually needs.
 */
class DashboardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'today_sales_total' => $data['today_sales_total'],
            'today_order_count' => $data['today_order_count'],
            'low_stock_variant_count' => $data['low_stock_variant_count'],
            'active_product_count' => $data['active_product_count'],
            'recent_orders' => collect($data['recent_orders'])->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $order->total,
                'status' => $order->status,
                'source' => $order->source,
                'created_at' => $order->created_at,
            ]),
            'low_stock_variants' => collect($data['low_stock_variants'])->map(fn ($variant) => [
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->variant_name,
                'current_stock' => $variant->current_stock,
                'low_stock_threshold' => $variant->low_stock_threshold,
            ]),
        ];
    }
}
