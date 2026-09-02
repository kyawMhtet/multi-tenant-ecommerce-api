<?php

namespace App\Exceptions;

use App\Services\Billing\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The shop is at its plan's ceiling for a countable resource.
 *
 * Thrown only on CREATE. A shop that drops to a plan whose limit it already
 * exceeds keeps everything it has — nothing is deleted, hidden or made
 * unreadable to force an upgrade. Being over the limit is a coherent state,
 * the same way a variant at -7 stock is: it records something true rather
 * than something broken.
 */
class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limit,
        public readonly int $maximum,
        public readonly int $current,
        public readonly string $plan,
    ) {
        parent::__construct("Plan [{$plan}] allows {$maximum} {$limit}; the shop has {$current}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => "Your plan includes up to {$this->maximum} ".str_replace('_', ' ', $this->limit).'. Upgrade to add more.',
            'reason' => 'plan_limit_exceeded',
            'limit' => $this->limit,
            'maximum' => $this->maximum,
            'current' => $this->current,
            'current_plan' => $this->plan,
            'current_plan_label' => PlanCatalog::labelFor($this->plan),
        ], 402);
    }
}
