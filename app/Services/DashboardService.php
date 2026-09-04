<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Tenants\BusinessDay;
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
        // The SHOP's day, not UTC's. tenants.timezone exists because Yangon
        // is UTC+06:30 and Bangkok UTC+07:00 — reading "today" off the server
        // clock put every sale a Yangon shop made before 06:30 into
        // yesterday's card.
        [$dayStart, $dayEnd] = BusinessDay::todayRange();

        // A bare timestamp comparison rather than whereDate(): DATE(created_at)
        // wraps the column in a function, so no index on it can ever be used.
        $todayOrders = Order::where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd);

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
