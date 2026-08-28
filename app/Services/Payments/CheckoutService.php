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
 * Orchestrates storefront checkout: create the order, then start its
 * payment.
 *
 * Deliberately separate from OrderService. Order creation is about stock
 * and money owed; this is about collecting it. Keeping them apart means
 * OrderService never depends on the payment layer, so the POS flow (which
 * has no gateway at all) stays untouched by any of this.
 */
class CheckoutService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentGatewayManager $gateways,
        private readonly ImageUploadService $imageUploadService,
    ) {}

    /**
     * The ordering here is the important part.
     *
     * The order is created and committed FIRST, in its own transaction,
     * and only then does the gateway get called. Calling a payment
     * provider from inside the transaction would be a genuine bug, not
     * just untidy: createOrderItems() takes row locks on every variant it
     * deducts, and holding those across a network round-trip to Stripe
     * means every other checkout touching the same product blocks until
     * Stripe answers. A slow gateway would become a site-wide stall. It
     * would also leave an orphaned payment session behind whenever the
     * transaction rolled back afterwards.
     *
     * Committing first creates the opposite risk — an order exists with
     * stock reserved, and then payment initiation fails — so that case is
     * compensated explicitly: the order is cancelled, which returns the
     * stock via the existing ledgered path. Without that, a Stripe outage
     * would quietly eat inventory, and nothing would ever release it,
     * because the release depends on a session that was never created.
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
     * Re-fetched rather than trusted from the request. StorePublicOrderRequest
     * already validated that this method exists and is enabled for the
     * current tenant, but the service doesn't assume that check ran or was
     * correct — the same reasoning OrderService re-resolves cart lines
     * instead of trusting validated() alone. The tenant scope on
     * TenantPaymentMethod means another shop's method can't be found here
     * even if validation were bypassed entirely.
     */
    private function resolveMethod(string $method): TenantPaymentMethod
    {
        return TenantPaymentMethod::enabled()->where('method', $method)->firstOrFail();
    }

    /**
     * A pending Payment row is written for anything with a provider
     * reference, and it does double duty: it's the record that an attempt
     * was made (useful when supporting a customer who says they paid), and
     * it's how the webhook finds its way back to this order later, since
     * payments carries unique(['gateway','transaction_ref']).
     *
     * Manual methods (cash on delivery) produce no reference and so no row
     * — there is nothing pending with a provider, just an order awaiting a
     * human. Its Payment row gets created when someone confirms the cash
     * actually arrived.
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
     * Stores the customer's transfer screenshot against a pending payment,
     * for manual methods that were paid out-of-band.
     *
     * Recorded as status='pending', never 'success'. A screenshot is a
     * claim, not a payment — trivially forged, and frequently just wrong
     * (right amount, wrong shop). Only the shop confirming turns this into
     * money received, which is the same judgement they already make over
     * Facebook and Line today.
     *
     * Ignored for gateway-backed methods: a card payment's evidence is the
     * webhook, and accepting an image alongside it would only invite
     * someone to think the two are interchangeable.
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
     * Compensating action for a failed payment start. Cancelling routes
     * through updateOrderStatus() rather than touching stock directly, so
     * the restore goes through the same row-locked, ledgered,
     * double-credit-guarded path every other cancellation uses.
     *
     * A failure to release is logged and swallowed: the customer is about
     * to receive the original payment error, and replacing it with a
     * second, more confusing one would tell them less about what went
     * wrong. The stranded stock is recoverable by cancelling the order in
     * the admin UI; an unexplainable error page is not.
     */
    private function releaseOrder(Order $order, Throwable $original): void
    {
        try {
            $this->orderService->updateOrderStatus($order, ['status' => 'cancelled']);
        } catch (Throwable $releaseFailure) {
            Log::error('Failed to release stock after payment initiation failed.', [
                'order_id' => $order->id,
                'payment_error' => $original->getMessage(),
                'release_error' => $releaseFailure->getMessage(),
            ]);
        }
    }
}
