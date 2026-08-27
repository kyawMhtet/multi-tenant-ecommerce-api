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
     * Deliberately generic: getMessage() (with the real SKU and stock
     * count) is still what gets logged internally, but this exception is
     * now reachable from the fully unauthenticated public checkout route
     * too, not just authenticated POS staff — echoing exact stock levels
     * back to any anonymous caller who can guess a variant id would leak
     * real-time inventory data through an error message instead of a
     * response body, the exact thing the storefront resource's coarse
     * stock_status already avoids on the happy path.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'One or more items are no longer available in the requested quantity.',
        ], 422);
    }
}
