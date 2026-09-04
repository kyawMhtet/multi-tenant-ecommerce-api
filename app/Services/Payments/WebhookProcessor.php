<?php

namespace App\Services\Payments;

use App\Models\Concerns\TenantScope;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\Payments\Data\PaymentEvent;
use App\Services\Payments\Data\PaymentEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified, already-translated payment event to an order.
 *
 * Gateway-agnostic by construction: it receives a PaymentEvent, which
 * carries no provider-specific anything, so a second gateway needs no
 * changes here at all.
 */
class WebhookProcessor
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * A webhook carries neither a token nor an X-Tenant-Slug header, so no
     * tenant is bound — it's DERIVED from whichever transaction_ref resolves.
     * That's a sanctioned TenantScope bypass (see CLAUDE.md), and it doesn't
     * widen exposure: transaction_ref is provider-generated, recorded by us,
     * and the signature check means only the provider can present one.
     *
     * Precise bypass, never withoutGlobalScopes() — Order is soft-deletable,
     * and the blanket form would let a replayed webhook resurrect a deleted
     * order and mark it paid.
     *
     * Idempotency comes from the (gateway, transaction_ref) unique index plus
     * the lock: providers redeliver routinely, so a repeat must be a no-op.
     * The already-resolved check happens AFTER the lock — before it, two
     * simultaneous deliveries could both read 'pending'.
     *
     * An unknown reference is logged and ignored, not thrown: a 500 makes the
     * provider retry something that can never succeed.
     */
    public function process(string $gateway, PaymentEvent $event): void
    {
        DB::transaction(function () use ($gateway, $event) {
            $payment = Payment::withoutGlobalScope(TenantScope::class)
                ->where('gateway', $gateway)
                ->where('transaction_ref', $event->transactionRef)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('Payment webhook referenced an unknown transaction.', [
                    'gateway' => $gateway,
                    'transaction_ref' => $event->transactionRef,
                ]);

                return;
            }

            if ($payment->status !== 'pending') {
                return;
            }

            match ($event->type) {
                PaymentEventType::Succeeded => $this->markPaid($payment, $event),
                PaymentEventType::Expired, PaymentEventType::Failed => $this->markUnsuccessful($payment, $event),
            };
        });
    }

    /**
     * The amount is checked against the order total before anything is marked
     * paid — otherwise a session created for the wrong figure could settle an
     * expensive order. A mismatch is flagged for a human, never accepted.
     */
    private function markPaid(Payment $payment, PaymentEvent $event): void
    {
        $order = $this->resolveOrder($payment);

        if ($order === null) {
            return;
        }

        // Validated against what was actually CHARGED, not the order total —
        // a deposit order is charged its deposit, and comparing that to the
        // full total would flag every legitimate half-payment as a mismatch.
        $expected = $order->amountDueNow();

        if ($event->amount !== null && abs($event->amount - $expected) > 0.009) {
            Log::error('Payment amount did not match the amount charged; not marking paid.', [
                'order_id' => $order->id,
                'expected' => $expected,
                'received' => $event->amount,
            ]);

            $payment->update([
                'status' => 'failed',
                'meta' => ['mismatch' => true] + $event->raw,
            ]);

            return;
        }

        $payment->update([
            'status' => 'success',
            'paid_at' => now(),
            'meta' => $event->raw,
        ]);

        // Re-read from the ledger now the payment row is successful: a deposit
        // and its later balance are two payments against one order, so how much
        // has arrived is a SUM, never a flag.
        $settled = $order->fresh()->amountPaid() + 0.009 >= (float) $order->total;

        // Not updateOrderStatus(): its cancel branch restores stock, and here
        // the goods are genuinely sold. Only the payment state changes.
        //
        // A deposit leaves `status` at 'pending' deliberately. That column
        // tracks the COMMERCIAL lifecycle, and a half-paid preorder is not a
        // completed sale — the shop still has to source the goods and collect
        // the balance. payment_status carries the money story; conflating the
        // two would have the dashboard count an unfinished import as revenue.
        $order->update([
            'status' => $settled ? 'paid' : $order->status,
            'payment_status' => $settled ? 'paid' : 'partial',
        ]);
    }

    /**
     * Only EXPIRY releases stock. An expired session is over, so the
     * reservation must come back or abandoned checkouts eat inventory
     * forever. A declined card isn't abandoned — the customer may retry, and
     * pulling stock mid-retry would be wrong.
     */
    private function markUnsuccessful(Payment $payment, PaymentEvent $event): void
    {
        $payment->update([
            'status' => 'failed',
            'meta' => $event->raw,
        ]);

        if ($event->type !== PaymentEventType::Expired) {
            return;
        }

        $order = $this->resolveOrder($payment);

        if ($order === null) {
            return;
        }

        // cancelOrder() and the stock restore beneath it run tenant-scoped
        // queries, and a webhook arrives with no tenant context at all.
        app()->instance('tenant', $order->tenant);

        try {
            // cancelOrder(), not a bare status write, so a system cancellation
            // carries the same reason and timestamp a staff one does.
            // cancelled_by stays null — that null IS the "automatic" signal.
            $this->orderService->cancelOrder($order, [
                'cancellation_reason' => 'payment_expired',
            ], cancelledBy: null);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    /**
     * Only TenantScope is stripped, so a soft-deleted order stays invisible.
     * Returns null rather than throwing: a 500 gets retried forever, and a
     * deleted order will never come back.
     */
    private function resolveOrder(Payment $payment): ?Order
    {
        $order = $payment->order()->withoutGlobalScope(TenantScope::class)->first();

        if ($order === null) {
            Log::warning('Payment webhook resolved to an order that no longer exists.', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);
        }

        return $order;
    }
}
