<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-facing record of a payment attempt.
 *
 * proof_url is the whole point for manual methods: it's what the shop
 * looks at before deciding whether money actually arrived. Deliberately
 * absent from every customer-facing resource — one customer must never be
 * able to see another's transfer screenshot, which would leak their bank
 * details and name.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'amount' => $this->amount,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'proof_url' => $this->proof_path ? Storage::disk('public')->url($this->proof_path) : null,
            'created_at' => $this->created_at,
        ];
    }
}
