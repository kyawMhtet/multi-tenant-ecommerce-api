<?php

namespace App\Http\Resources;

use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\SubscriptionInvoice
 *
 * proof_url is the point of the manual rail: it's what the shop uploaded and
 * what a human will look at. Published back to the shop so they can see the
 * screenshot actually arrived — but note the status stays 'pending' next to
 * it, because an uploaded screenshot is a claim, not a payment.
 */
class SubscriptionInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => 'SUB-'.$this->id,
            'plan' => $this->plan,
            'plan_label' => PlanCatalog::labelFor($this->plan),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rail' => $this->gateway,
            'status' => $this->status,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'paid_at' => $this->paid_at,
            'proof_url' => $this->proof_path ? Storage::disk('public')->url($this->proof_path) : null,
            // Who reviewed it is internal — the shop only needs to know that
            // someone did, and when.
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
