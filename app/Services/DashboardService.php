<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Every query here is a plain Eloquent query against a BelongsToTenant
     * model — the ambient scope does the filtering, never a manual
     * ->where('tenant_id', ...).
     */
    public function getSummary(): array
    {
        $today = now()->toDateString();

        $todayOrders = Order::whereDate('created_at', $today);

        // Same constant as ReportService, so the today card and the range
        // report can't disagree about the same day's sales.
        $todaySalesTotal = (clone $todayOrders)
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->sum(DB::raw(Order::GOODS_REVENUE_SQL));

        $todayDeliveryFees = (clone $todayOrders)
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->sum('delivery_fee');

        $todayOrderCount = (clone $todayOrders)->count();

        $lowStockVariants = ProductVariant::with('product')
            ->lowStock()
            ->orderBy('current_stock')
            ->get();

        // Money taken but owed back. Surfaced because for manual methods the
        // refund happens in the shop's own banking app — nothing else will
        // ever remind them it's outstanding.
        $refundsOwed = Order::where('status', 'cancelled')
            ->where('payment_status', 'paid')
            ->whereNull('refunded_at')
            ->get(['id', 'order_number', 'total', 'cancelled_at']);

        // Its own figure rather than folded into low stock: the two need
        // opposite actions — reorder soon vs. chase the supplier today.
        // scopeLowStock() excludes negatives, so nothing is counted twice.
        $oversoldVariants = ProductVariant::with('product')
            ->oversold()
            ->orderBy('current_stock')
            ->get();

        $activeProductCount = Product::where('is_active', true)->count();

        $recentOrders = Order::latest()->take(10)->get();

        return [
            'today_sales_total' => round((float) $todaySalesTotal, 2),
            // Collected, but not margin — same reason as in the report.
            'today_delivery_fees' => round((float) $todayDeliveryFees, 2),
            'today_order_count' => $todayOrderCount,
            'low_stock_variant_count' => $lowStockVariants->count(),
            'active_product_count' => $activeProductCount,
            'refunds_owed_count' => $refundsOwed->count(),
            'refunds_owed_total' => round((float) $refundsOwed->sum('total'), 2),
            'preorder_backlog_variant_count' => $oversoldVariants->count(),
            // Positive: "-12 units outstanding" reads as a double negative.
            'preorder_backlog_units' => round((float) $oversoldVariants->sum(
                fn (ProductVariant $variant) => abs((float) $variant->current_stock)
            ), 2),
            'recent_orders' => $recentOrders,
            'low_stock_variants' => $lowStockVariants,
            'preorder_backlog_variants' => $oversoldVariants,
        ];
    }
}
