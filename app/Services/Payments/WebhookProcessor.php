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
     * Finds the pending payment this event refers to, then applies it.
     *
     * The TenantScope bypass is unavoidable here and is a third sanctioned
     * case alongside the two CLAUDE.md already documents: a webhook is a
     * server-to-server call with no authenticated user and no
     * X-Tenant-Slug header, so no tenant is bound when this runs — the
     * tenant is *derived* from whichever transaction reference resolves,
     * rather than being known up front. Crucially the bypass is precise
     * (withoutGlobalScope(TenantScope::class)), never the blanket
     * withoutGlobalScopes(): Order is soft-deletable, and the blanket form
     * would also strip SoftDeletingScope and let a deleted order be
     * resurrected and marked paid.
     *
     * This does NOT widen cross-tenant exposure, because the lookup key is
     * not attacker-chosen: transaction_ref is a provider-generated id
     * recorded by us when the session was created, and the surrounding
     * signature check means only the provider can present one.
     *
     * The lookup is by (gateway, transaction_ref) — the same pair the
     * payments table has a unique index on — which is what makes the whole
     * thing idempotent. Providers redeliver webhooks routinely (a timeout,
     * a retry, an operator replaying an event), so "the same event arriving
     * twice" is normal traffic rather than an error, and must not produce a
     * second payment or a second stock movement.
     *
     * The row is locked and re-read inside the transaction, and the
     * already-resolved check happens after that lock. Checking before it
     * would leave a real race: two simultaneous deliveries could both read
     * status='pending' and both proceed.
     *
     * An unrecognised reference is logged and ignored rather than thrown.
     * It genuinely happens — a session created against a different
     * environment sharing the same webhook endpoint, say — and answering
     * with an error would make the provider retry something that can never
     * succeed.
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
     * The amount is checked against what the order actually costs before
     * anything is marked paid. The provider's amount was set by whoever
     * created the session, so treating it as authoritative would mean a
     * session created for the wrong figure could settle an expensive order.
     * A mismatch is deliberately NOT treated as payment: it's flagged for a
     * human, since silently accepting it loses money and silently
     * refunding it would be worse.
     */
    private function markPaid(Payment $payment, PaymentEvent $event): void
    {
        $order = $this->resolveOrder($payment);

        if ($order === null) {
            return;
        }

        if ($event->amount !== null && abs($event->amount - (float) $order->total) > 0.009) {
            Log::error('Payment amount did not match the order total; not marking paid.', [
                'order_id' => $order->id,
                'expected' => (float) $order->total,
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

        // Not updateOrderStatus(): that path is for a human changing an
        // order, and its cancel branch restores stock. Here the stock was
        // already deducted at order creation and the goods are genuinely
        // sold, so only the payment state changes.
        $order->update([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);
    }

    /**
     * Expiry and failure both end the attempt, but only expiry releases
     * stock.
     *
     * The difference is whether the customer can still complete this
     * order. An expired session is over — the reservation has to come back
     * or abandoned checkouts silently consume inventory forever. A failed
     * payment (a declined card) leaves the order intact so the customer can
     * retry with another card, and pulling stock out from under them
     * mid-retry would be the wrong call.
     *
     * Cancellation routes through updateOrderStatus() so the restore uses
     * the same row-locked, ledgered, double-credit-guarded path as every
     * other cancellation.
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

        // Bind the tenant the order belongs to: updateOrderStatus() and the
        // stock restore beneath it run tenant-scoped queries, and a webhook
        // arrives with no tenant context at all — nobody is logged in and
        // there's no X-Tenant-Slug header on a server-to-server call.
        app()->instance('tenant', $order->tenant);

        try {
            $this->orderService->updateOrderStatus($order, ['status' => 'cancelled']);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    /**
     * Only TenantScope is stripped, so SoftDeletingScope still applies and
     * a soft-deleted order stays invisible here — a replayed webhook must
     * never resurrect one and mark it paid.
     *
     * Returns null rather than throwing when the order can't be resolved.
     * A webhook that 500s gets retried by the provider indefinitely, and
     * an order that has been deleted will never come back, so retrying
     * could only ever fail again. Log it for a human and answer 200.
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
