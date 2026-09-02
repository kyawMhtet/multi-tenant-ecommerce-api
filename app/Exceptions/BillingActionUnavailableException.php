<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The shop asked for something billing cannot do from its current state —
 * a rail this deployment has not configured, or a change that would collide
 * with a live provider subscription.
 *
 * 422, not 402. Nothing here is fixed by paying; the request itself doesn't
 * apply. Distinguishing them matters because the admin app renders 402 as an
 * upgrade prompt, which would be nonsense here.
 */
class BillingActionUnavailableException extends RuntimeException
{
    public function __construct(private readonly string $publicMessage)
    {
        parent::__construct($publicMessage);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->publicMessage,
            'reason' => 'billing_action_unavailable',
        ], 422);
    }
}
