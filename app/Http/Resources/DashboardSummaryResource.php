<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array, not a model. Deliberately narrower than the full
 * resources for the nested lists: no cost fields, nothing beyond what a
 * dashboard card actually renders.
 */
class DashboardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'today_sales_total' => $data['today_sales_total'],
            'today_delivery_fees' => $data['today_delivery_fees'],
            'today_order_count' => $data['today_order_count'],
            'low_stock_variant_count' => $data['low_stock_variant_count'],
            'active_product_count' => $data['active_product_count'],
            // Cancelled orders whose money the shop still has to send back.
            'refunds_owed_count' => $data['refunds_owed_count'],
            'refunds_owed_total' => $data['refunds_owed_total'],
            // Items already sold on preorder that haven't arrived yet.
            'preorder_backlog_variant_count' => $data['preorder_backlog_variant_count'],
            'preorder_backlog_units' => $data['preorder_backlog_units'],
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
            'preorder_backlog_variants' => collect($data['preorder_backlog_variants'])->map(fn ($variant) => [
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->variant_name,
                // Positive: the raw current_stock is negative.
                'units_owed' => abs((float) $variant->current_stock),
                'preorder_lead_time_days' => $variant->preorder_lead_time_days,
            ]),
        ];
    }
}
