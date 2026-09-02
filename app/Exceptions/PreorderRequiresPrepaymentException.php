<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A preorder line the shop won't send on deferred payment, with a deferred
 * method chosen. Raised inside the order transaction so the whole order rolls
 * back — no half-created order, no stock deducted for a refused sale.
 */
class PreorderRequiresPrepaymentException extends RuntimeException
{
    public function __construct(public readonly ProductVariant $variant)
    {
        parent::__construct(
            "Variant [{$variant->sku}] is on preorder and requires prepayment."
        );
    }

    /**
     * Specific, unlike InsufficientStockException: it reveals only that an item
     * is on preorder, which stock_status already publishes, and the customer
     * can't fix it without being told. Still no SKU, no stock figure.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'One or more items are on preorder and must be paid for in advance. Please choose a different payment method.',
        ], 422);
    }
}
