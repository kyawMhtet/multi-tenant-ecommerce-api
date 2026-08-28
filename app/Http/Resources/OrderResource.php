<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
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
            // What the customer chose to pay with. Distinct from any
            // payments row: a cash-on-delivery order has none until a
            // human confirms the money arrived, so without this the shop
            // owner can't tell an order awaiting a delivery driver from
            // one awaiting a gateway.
            'payment_method' => $this->payment_method,
            'fulfillment_type' => $this->fulfillment_type,
            // The snapshot taken at checkout, not the customer's current
            // address — this is where the order actually goes. Null for
            // pickup.
            'delivery_address' => $this->delivery_address,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'currency' => $this->currency,
            'cashier_id' => $this->cashier_id,
            'cashier_name' => $this->whenLoaded('cashier', fn () => $this->cashier?->name),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'created_at' => $this->created_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            // Only loaded on admin order views. Carries the customer's
            // transfer screenshot for manual methods, which is what the
            // shop needs to see before confirming payment.
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
