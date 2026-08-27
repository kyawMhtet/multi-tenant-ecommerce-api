<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;

class DashboardService
{
    /**
     * No tenant parameter, same as every other Service in this app —
     * relies entirely on the ambient tenant scope already bound by the
     * 'tenant' middleware before this ever runs. Every query below is a
     * plain Eloquent query against a BelongsToTenant model for exactly
     * that reason: the scope does the filtering, this never adds
     * ->where('tenant_id', ...) by hand.
     */
    public function getSummary(): array
    {
        $today = now()->toDateString();

        $todayOrders = Order::whereDate('created_at', $today);

        $todaySalesTotal = (clone $todayOrders)
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->sum('total');

        $todayOrderCount = (clone $todayOrders)->count();

        $lowStockVariants = ProductVariant::with('product')
            ->lowStock()
            ->orderBy('current_stock')
            ->get();

        $activeProductCount = Product::where('is_active', true)->count();

        $recentOrders = Order::latest()->take(10)->get();

        return [
            'today_sales_total' => round((float) $todaySalesTotal, 2),
            'today_order_count' => $todayOrderCount,
            'low_stock_variant_count' => $lowStockVariants->count(),
            'active_product_count' => $activeProductCount,
            'recent_orders' => $recentOrders,
            'low_stock_variants' => $lowStockVariants,
        ];
    }
}
