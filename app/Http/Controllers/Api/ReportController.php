<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesProfitReportRequest;
use App\Http\Resources\SalesProfitReportResource;
use App\Services\ReportService;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function salesProfit(SalesProfitReportRequest $request)
    {
        return new SalesProfitReportResource(
            $this->reportService->getSalesProfitReport($request->validated())
        );
    }
}
