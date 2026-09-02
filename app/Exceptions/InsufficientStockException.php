<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly ProductVariant $variant, public readonly float $requestedQuantity)
    {
        parent::__construct(
            "Insufficient stock for variant [{$variant->sku}]: requested {$requestedQuantity}, have {$variant->current_stock}."
        );
    }

    /**
     * Deliberately generic. getMessage() still logs the real SKU and count, but
     * this is reachable from the unauthenticated checkout — echoing exact stock
     * to an anonymous caller would leak through an error what stock_status
     * carefully avoids leaking through a response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'One or more items are no longer available in the requested quantity.',
        ], 422);
    }
}
