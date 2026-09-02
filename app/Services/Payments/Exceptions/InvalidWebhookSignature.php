<?php

namespace App\Services\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * An exception rather than a boolean return so it can never be ignored: an
 * unverified webhook is an anonymous request asserting an order was paid.
 *
 * 400, not 401/403 — a malformed machine request, and providers read 4xx as
 * "don't retry", which is right: a bad signature won't improve on redelivery.
 */
class InvalidWebhookSignature extends RuntimeException
{
    public function __construct(string $message = 'Invalid webhook signature.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 400);
    }
}
