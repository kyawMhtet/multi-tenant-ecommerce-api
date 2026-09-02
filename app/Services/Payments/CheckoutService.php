<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\TenantPaymentMethod;
use Illuminate\Http\UploadedFile;
use App\Services\ImageUploadService;
use App\Services\OrderService;
use App\Services\Payments\Data\CheckoutResult;
use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Storefront checkout: create the order, then start its payment.
 *
 * Separate from OrderService so that service never depends on the payment
 * layer — the POS flow has no gateway at all.
 */
class CheckoutService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentGatewayManager $gateways,
        private readonly ImageUploadService $imageUploadService,
    ) {}

    /**
     * The ordering is the important part: commit the order FIRST, then call
     * the gateway. createOrderItems() holds row locks on every variant it
     * deducts, and holding those across a network round-trip would stall
     * every checkout touching the same product.
     *
     * Committing first creates the opposite risk — stock reserved, then
     * initiation fails — so that's compensated explicitly by cancelling,
     * which returns the stock through the ledgered path.
     */
    public function checkout(array $data): CheckoutResult
    {
        $method = $this->resolveMethod($data['payment_method']);

        $order = $this->orderService->createOnlineOrder($data);

        try {
            $initiation = $this->initiatePayment($order, $method);
            $this->recordProofOfPayment($order, $method, $data['payment_proof'] ?? null);
        } catch (Throwable $e) {
            $this->releaseOrder($order, $e);

            throw $e;
        }

        return new CheckoutResult($order, $initiation);
    }

    /**
     * Re-fetched rather than trusted from the request — the tenant scope means
     * another shop's method isn't found even if validation were bypassed.
     */
    private function resolveMethod(string $method): TenantPaymentMethod
    {
        return TenantPaymentMethod::enabled()->where('method', $method)->firstOrFail();
    }

    /**
     * The pending row is how the webhook finds its way back to this order,
     * via payments.unique(['gateway','transaction_ref']). Manual methods
     * produce no reference and so no row — nothing is pending with a
     * provider, just an order awaiting a human.
     */
    private function initiatePayment(Order $order, TenantPaymentMethod $method): PaymentInitiation
    {
        $initiation = $this->gateways->for($method)->initiate($order, $method);

        if ($initiation->reference !== null) {
            Payment::create([
                'order_id' => $order->id,
                'gateway' => $method->gateway,
                'amount' => $order->total,
                'status' => 'pending',
                'transaction_ref' => $initiation->reference,
            ]);
        }

        return $initiation;
    }

    /**
     * Always status='pending', never 'success'. A screenshot is a claim, not a
     * payment — trivially forged and often just wrong (right amount, wrong
     * shop). Only the shop confirming turns it into money received.
     *
     * Ignored for gateway-backed methods: a card payment's evidence is its
     * webhook, and accepting an image alongside invites treating them as
     * interchangeable.
     */
    private function recordProofOfPayment(Order $order, TenantPaymentMethod $method, ?UploadedFile $proof): void
    {
        if ($proof === null || ! $method->isManual()) {
            return;
        }

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'manual',
            'amount' => $order->total,
            'status' => 'pending',
            'proof_path' => $this->imageUploadService->store($proof, 'payment-proofs/'.$order->tenant_id),
        ]);
    }

    /**
     * Compensating action for a failed payment start.
     *
     * A failure to release is logged and swallowed: the customer is about to
     * get the original payment error, and replacing it with a second, more
     * confusing one tells them less. Stranded stock is recoverable from the
     * admin UI; an unexplainable error page is not.
     */
    private function releaseOrder(Order $order, Throwable $original): void
    {
        try {
            // cancelOrder(), not a bare status write, for the same reason
            // WebhookProcessor uses it. cancelled_by stays null — nobody did
            // this, the gateway was unreachable.
            $this->orderService->cancelOrder($order, [
                'cancellation_reason' => 'payment_initiation_failed',
            ], cancelledBy: null);
        } catch (Throwable $releaseFailure) {
            Log::error('Failed to release stock after payment initiation failed.', [
                'order_id' => $order->id,
                'payment_error' => $original->getMessage(),
                'release_error' => $releaseFailure->getMessage(),
            ]);
        }
    }
}
