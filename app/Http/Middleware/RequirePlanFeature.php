<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanFeature;
use App\Services\Billing\PlanGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * Route-level feature gate: `->middleware('plan:profit_reports')`.
 *
 * Used where a whole endpoint belongs to a plan. Where a feature is one FIELD
 * on a larger request — allow_preorder on a variant, say — the check belongs
 * in the service instead, so the rest of the request still succeeds and the
 * shop isn't told its entire product edit was rejected.
 */
class RequirePlanFeature
{
    public function __construct(private readonly PlanGate $gate) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // A typo'd feature name is a programmer error, not a customer one, so
        // it throws rather than silently gating nothing (or, worse, silently
        // gating everything). ValueError surfaces as a 500 in development the
        // first time the route is hit.
        $this->gate->ensureFeature(
            PlanFeature::tryFrom($feature)
                ?? throw new ValueError("Unknown plan feature [{$feature}] in route middleware.")
        );

        return $next($request);
    }
}
