<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Services\Orders\CancellationReasonCatalog;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'source' => $this->source,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            // Distinct from any payments row: a COD order has none until a
            // human confirms, so without this the shop can't tell an order
            // awaiting a driver from one awaiting a gateway.
            'payment_method' => $this->payment_method,
            'fulfillment_type' => $this->fulfillment_type,

            // The NAME is the snapshot, not the relation, so it survives the
            // provider row being deleted; the id is for grouping and goes null.
            'delivery_provider_id' => $this->delivery_provider_id,
            'delivery_provider_name' => $this->delivery_provider_name,
            'tracking_number' => $this->tracking_number,
            'is_dispatched' => $this->isDispatched(),
            'dispatched_at' => $this->dispatched_at,
            'dispatched_by_name' => $this->whenLoaded('dispatchedBy', fn () => $this->dispatchedBy?->name),

            // Why this order can sit at 'pending' for weeks without being
            // stalled. Derived, so a mixed cart reports honestly.
            'has_preorder_items' => $this->hasPreorderItems(),
            // Needs the lines to compute a date, so detail views only.
            'preorder_ready_by' => $this->whenLoaded('items', fn () => $this->preorderReadyBy()),

            // reason_label is resolved server-side so wording stays consistent
            // and can be translated without every client shipping the list.
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_reason_label' => CancellationReasonCatalog::labelFor($this->cancellation_reason),
            'cancellation_note' => $this->cancellation_note,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by_name' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),

            // Derived server-side rather than left to the client to work out
            // from three fields — getting it wrong means either nagging about a
            // refund already sent or dropping one that wasn't.
            'refund_required' => $this->refundRequired(),
            'refunded_at' => $this->refunded_at,
            'refund_note' => $this->refund_note,
            'refunded_by_name' => $this->whenLoaded('refundedBy', fn () => $this->refundedBy?->name),
            // The checkout snapshot, not the customer's current address.
            'delivery_address' => $this->delivery_address,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            // Snapshotted at checkout, not the shop's fee today. In total.
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'currency' => $this->currency,
            'cashier_id' => $this->cashier_id,
            'cashier_name' => $this->whenLoaded('cashier', fn () => $this->cashier?->name),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'created_at' => $this->created_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            // Admin views only — carries the transfer screenshot the shop needs
            // to see before confirming a manual payment.
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
