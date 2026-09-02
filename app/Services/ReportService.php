<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Revenue and cost are two queries, not one join: joining order_items onto
     * orders and summing orders.total would FAN OUT — a 3-line order would
     * count its total three times. Both use Order::REVENUE_STATUSES so they
     * can't disagree with the dashboard about the same day.
     */
    public function getSalesProfitReport(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        $eligibleOrders = Order::query()
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        // Goods only — see Order::GOODS_REVENUE_SQL for why.
        $revenue = round((float) (clone $eligibleOrders)->sum(DB::raw(Order::GOODS_REVENUE_SQL)), 2);
        $deliveryFees = round((float) (clone $eligibleOrders)->sum('delivery_fee'), 2);
        $orderCount = (clone $eligibleOrders)->count();

        // A DB-side aggregate, never loading rows into PHP: O(1) round-trips
        // however many orders are in range. whereHas applies the same filter
        // without a join or fan-out.
        $cost = round((float) OrderItem::query()
            ->whereHas('order', function ($query) use ($dateFrom, $dateTo) {
                $query->whereIn('status', Order::REVENUE_STATUSES)
                    ->whereDate('created_at', '>=', $dateFrom)
                    ->whereDate('created_at', '<=', $dateTo);
            })
            ->sum(DB::raw('unit_cost * quantity')), 2);

        $profit = round($revenue - $cost, 2);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'revenue' => $this->money($revenue),
            'cost' => $this->money($cost),
            'profit' => $this->money($profit),
            // Alongside, not folded in: real money banked, so hiding it would
            // stop the report reconciling against the till, but it isn't
            // margin. revenue + this is what actually came in.
            'delivery_fees_collected' => $this->money($deliveryFees),
            // null, not 0: 0 reads as "broke even", not "no data".
            'margin_percentage' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : null,
            'order_count' => $orderCount,
            'average_order_value' => $orderCount > 0 ? $this->money($revenue / $orderCount) : null,
            'daily' => $this->getDailyBreakdown($dateFrom, $dateTo),
        ];
    }

    /**
     * Every other money value here comes from a decimal:2 cast, which
     * serializes as "400.00". Floats drop trailing zeros in JSON (400.0 → 400),
     * so these computed aggregates reproduce that formatting by hand.
     */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Every calendar day in range, not just days with orders — a trend chart
     * needs a continuous x-axis, and a quiet day is information.
     *
     * The cost query needs a real join here: GROUP BY needs orders.created_at,
     * which an EXISTS-based whereHas can't expose. TenantScope table-qualifies
     * its where clause, so it still applies alongside the join.
     */
    private function getDailyBreakdown(string $dateFrom, string $dateTo): array
    {
        $dailyOrders = Order::query()
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as order_count, SUM('.Order::GOODS_REVENUE_SQL.') as revenue')
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $dailyCost = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', Order::REVENUE_STATUSES)
            ->whereDate('orders.created_at', '>=', $dateFrom)
            ->whereDate('orders.created_at', '<=', $dateTo)
            ->selectRaw('DATE(orders.created_at) as date, SUM(order_items.unit_cost * order_items.quantity) as cost')
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        return collect(CarbonPeriod::create($dateFrom, $dateTo))
            ->map(function (Carbon $day) use ($dailyOrders, $dailyCost) {
                $date = $day->toDateString();
                $revenue = round((float) ($dailyOrders[$date]->revenue ?? 0), 2);
                $cost = round((float) ($dailyCost[$date]->cost ?? 0), 2);

                return [
                    'date' => $date,
                    'revenue' => $this->money($revenue),
                    'cost' => $this->money($cost),
                    'profit' => $this->money($revenue - $cost),
                    'order_count' => (int) ($dailyOrders[$date]->order_count ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
