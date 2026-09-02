<?php

namespace App\Exceptions;

use App\Services\Billing\PlanCatalog;
use App\Services\Billing\PlanFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** The shop's plan does not include the capability it just tried to use. */
class FeatureNotOnPlanException extends RuntimeException
{
    public function __construct(
        public readonly PlanFeature $feature,
        public readonly string $plan,
    ) {
        parent::__construct("Feature [{$feature->value}] is not available on plan [{$plan}].");
    }

    /**
     * Names the feature and the current plan, but deliberately not a price or
     * a "upgrade to Pro for X" string. Pricing lives in config and changes;
     * an error message repeating it is a second copy that will eventually
     * quote the wrong number to a customer.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'This feature is not included in your current plan.',
            'reason' => 'feature_not_on_plan',
            'feature' => $this->feature->value,
            'current_plan' => $this->plan,
            'current_plan_label' => PlanCatalog::labelFor($this->plan),
        ], 402);
    }
}
