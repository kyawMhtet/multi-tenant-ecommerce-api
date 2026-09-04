<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ApproveInvoiceRequest;
use App\Http\Requests\Platform\IndexPlatformInvoiceRequest;
use App\Http\Requests\Platform\RejectInvoiceRequest;
use App\Http\Requests\Platform\SetBillingCurrencyRequest;
use App\Http\Resources\PlatformInvoiceResource;
use App\Http\Resources\PlatformSubscriptionResource;
use App\Services\Platform\SubscriptionReviewService;
use Illuminate\Http\JsonResponse;

/**
 * The queue of bank transfers waiting on a human, and the two rulings.
 *
 * {invoice} is an id, NOT a route-model binding, and that is deliberate:
 * implicit binding would resolve SubscriptionInvoice through its global
 * scope, which happens to no-op for a platform admin because there is no
 * tenant bound. Relying on that would make cross-tenant access an accident of
 * who is logged in. The service resolves it with an explicit
 * withoutGlobalScope() instead, so the intent is stated rather than inferred.
 */
class SubscriptionReviewController extends Controller
{
    public function __construct(private readonly SubscriptionReviewService $review) {}

    public function pending(): JsonResponse
    {
        return PlatformInvoiceResource::collection($this->review->pending())->response();
    }

    /**
     * Shops that asked how to pay and sent nothing. A chase list — nothing
     * here is actionable, which is exactly why it is not in the queue.
     */
    public function awaitingTransfer(): JsonResponse
    {
        return PlatformInvoiceResource::collection($this->review->awaitingTransfer())->response();
    }

    /**
     * The full ledger, as opposed to pending() above which is the review
     * QUEUE. Different question: history to reconcile against a bank
     * statement, rather than work waiting to be done.
     */
    public function invoices(IndexPlatformInvoiceRequest $request): JsonResponse
    {
        return PlatformInvoiceResource::collection(
            $this->review->invoices($request->filters(), $request->integer('per_page', 25))
        )->response();
    }

    public function approve(ApproveInvoiceRequest $request, int $invoice): JsonResponse
    {
        return (new PlatformInvoiceResource(
            $this->review->approve($invoice, $request->user(), $request->validated('note'))
        ))->response();
    }

    /**
     * Which currency a shop is billed in — the account it transfers to and
     * which price list applies. Platform-only because, left to the shop, it
     * would be an arbitrage lever rather than a preference: the ladders are
     * not at parity across currencies and the gap moves with FX.
     *
     * Send `currency: null` to restore the default of following the shop's own
     * selling currency.
     */
    public function billingCurrency(SetBillingCurrencyRequest $request, int $subscription): JsonResponse
    {
        return (new PlatformSubscriptionResource(
            $this->review->setBillingCurrency($subscription, $request->validated('currency'))
        ))->response();
    }

    public function reject(RejectInvoiceRequest $request, int $invoice): JsonResponse
    {
        return (new PlatformInvoiceResource(
            $this->review->reject($invoice, $request->user(), $request->validated('reason'))
        ))->response();
    }
}
