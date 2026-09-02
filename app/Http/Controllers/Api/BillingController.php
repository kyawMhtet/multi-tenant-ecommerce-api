<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartSubscriptionRequest;
use App\Http\Requests\UploadPaymentProofRequest;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionInvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingCurrency;
use App\Services\Billing\BillingRailManager;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;

/**
 * No route here takes a tenant identifier: every action reads app('tenant'),
 * which ResolveTenant derives from the token's owner. A shop can only ever
 * bill itself.
 *
 * None of these routes sit behind the 'subscription' middleware, and that is
 * load-bearing rather than an oversight — putting the renew button behind an
 * active subscription would be the one bug in this feature a customer could
 * not recover from without support.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BillingRailManager $rails,
    ) {}

    /**
     * Everything the billing screen needs in one call: current state, plus
     * the full plan catalogue with what each costs and how it can be paid
     * for. Same reasoning as PaymentMethodController::index returning the
     * whole catalogue — a client that received only its current plan would
     * need its own copy of what else exists, which is how a frontend ends up
     * quoting a price the server no longer charges.
     */
    public function show(): JsonResponse
    {
        $tenant = app('tenant');
        $subscription = $tenant->subscription;

        // Resolved once, from the shop's own (immutable) currency. Every
        // price and every available rail below is answered in it, so the
        // billing screen can never quote Baht to a shop that will be asked
        // to transfer Kyat.
        $currency = BillingCurrency::for($tenant);

        $plans = collect(PlanCatalog::codes())->map(fn (string $code) => [
            'code' => $code,
            'currency' => $currency,
            'rails' => $this->rails->availableFor($code, $currency),
            'is_current' => $subscription !== null && $code === $subscription->effectivePlan(),
        ]);

        return response()->json([
            'data' => [
                'currency' => $currency,
                'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
                'plans' => PlanResource::collection($plans),
            ],
        ]);
    }

    public function invoices(): JsonResponse
    {
        return SubscriptionInvoiceResource::collection(
            app('tenant')->subscriptionInvoices()->latest('id')->paginate(20)
        )->response();
    }

    /**
     * 200, not 201: this creates nothing the caller owns. It returns what to
     * do next — a Stripe redirect, or bank details plus the invoice to
     * reference. The plan has NOT changed at this point and the response
     * deliberately does not imply it has.
     */
    public function subscribe(StartSubscriptionRequest $request): JsonResponse
    {
        $initiation = $this->subscriptions->subscribe(
            app('tenant')->subscription,
            $request->validated('plan'),
            $request->validated('rail'),
        );

        return response()->json(['data' => [
            'type' => $initiation->type->value,
            'url' => $initiation->url,
            'instructions' => $initiation->instructions ?: null,
            'invoice' => $initiation->invoice
                ? new SubscriptionInvoiceResource($initiation->invoice)
                : null,
        ]]);
    }

    /**
     * {invoice} is resolved through the tenant-scoped model, so another
     * shop's invoice is a 404 rather than a 403 — same as everywhere else
     * here.
     */
    public function proof(UploadPaymentProofRequest $request, SubscriptionInvoice $invoice): JsonResponse
    {
        return (new SubscriptionInvoiceResource(
            $this->subscriptions->submitProof($invoice, $request->file('proof'))
        ))->response();
    }

    public function cancel(): JsonResponse
    {
        return (new SubscriptionResource(
            $this->subscriptions->cancel(app('tenant')->subscription)
        ))->response();
    }
}
