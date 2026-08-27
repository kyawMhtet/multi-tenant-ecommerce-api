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
     * No tenant parameter, same as every other Service in this app —
     * relies entirely on the ambient tenant scope already bound by the
     * 'tenant' middleware. Revenue and cost are two separate queries, not
     * one join: joining order_items onto orders and summing orders.total
     * would fan out (an order with 3 line items would count its total 3
     * times), so revenue sums from Order alone and cost sums from
     * OrderItem, each independently filtered by the same status/date
     * window via Order::REVENUE_STATUSES — the same "what counts as a
     * real sale" rule DashboardService's today card uses, so the two can
     * never quietly disagree about the same day's numbers.
     */
    public function getSalesProfitReport(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        $eligibleOrders = Order::query()
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        $revenue = round((float) (clone $eligibleOrders)->sum('total'), 2);
        $orderCount = (clone $eligibleOrders)->count();

        // Computed as a DB-side aggregate, never by loading OrderItem rows
        // into PHP and summing — keeps this O(1) round-trips regardless of
        // how many orders fall in the range. whereHas applies the same
        // status/date filter as $eligibleOrders without a join or fan-out.
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
            // null, not 0, when there's nothing to divide by — 0 would
            // misleadingly read as "broke even" / "orders averaged $0"
            // instead of "no data for this range."
            'margin_percentage' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : null,
            'order_count' => $orderCount,
            'average_order_value' => $orderCount > 0 ? $this->money($revenue / $orderCount) : null,
            'daily' => $this->getDailyBreakdown($dateFrom, $dateTo),
        ];
    }

    /**
     * Every other money value in this API comes from a decimal:2 Eloquent
     * cast, which Laravel serializes as a fixed-2-decimal string ("400.00"),
     * not a float — floats silently drop trailing zeros in JSON (400.0
     * becomes 400), which would make this the one endpoint in the app
     * whose money fields format inconsistently. These values are computed
     * aggregates, not cast columns, so this reproduces that formatting by
     * hand rather than inheriting it for free.
     */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Two grouped queries merged over every calendar day in range, not
     * just days with orders — a trend chart needs a continuous x-axis,
     * and a quiet day (0 sales) is meaningful information, not an absent
     * data point. The cost query needs an actual join here (unlike the
     * totals query above): GROUP BY needs a column from the related table
     * (orders.created_at), which an EXISTS-based whereHas subquery can't
     * expose to the outer query's SELECT/GROUP BY. The join doesn't
     * disable OrderItem's own tenant scope — TenantScope table-qualifies
     * its where clause, so it still applies correctly alongside the join.
     */
    private function getDailyBreakdown(string $dateFrom, string $dateTo): array
    {
        $dailyOrders = Order::query()
            ->whereIn('status', Order::REVENUE_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as order_count, SUM(total) as revenue')
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
