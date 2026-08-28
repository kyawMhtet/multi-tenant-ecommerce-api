<?php

namespace App\Services\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * A webhook arrived that didn't carry a valid signature from the provider.
 *
 * Treated as an exception rather than a boolean return so it can never be
 * accidentally ignored: an unverified webhook is an anonymous HTTP request
 * asserting that an order was paid, and quietly continuing past one would
 * let anyone who knows the endpoint mark orders as paid for free.
 *
 * Renders 400 rather than 401/403 — this is a malformed request from a
 * machine, not a permissions problem for a user, and providers interpret a
 * 4xx as "don't bother retrying this one", which is correct here: a
 * badly-signed payload will never become well-signed on redelivery.
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
