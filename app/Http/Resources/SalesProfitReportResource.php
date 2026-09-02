<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array from ReportService::getSalesProfitReport(), not an
 * Eloquent model — same pattern as DashboardSummaryResource.
 */
class SalesProfitReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'revenue' => $data['revenue'],
            'cost' => $data['cost'],
            'profit' => $data['profit'],
            'delivery_fees_collected' => $data['delivery_fees_collected'],
            'margin_percentage' => $data['margin_percentage'],
            'order_count' => $data['order_count'],
            'average_order_value' => $data['average_order_value'],
            'daily' => $data['daily'],
        ];
    }
}
