<?php

namespace App\Http\Resources;

use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\SubscriptionInvoice
 *
 * The staff-facing view of an invoice, and deliberately a different resource
 * from SubscriptionInvoiceResource rather than a flag on it. This one names
 * the SHOP, which is the whole point for a reviewer and must never appear in
 * a response to a shop — the same reason DeliveryProviderResource is
 * admin-only. Two resources cannot leak into each other; one resource with a
 * conditional can.
 *
 * proof_url is what the reviewer actually looks at, next to the bank
 * statement. It is a claim, not evidence — the reviewer's job is precisely to
 * decide whether it matches money that arrived.
 */
class PlatformInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What the shop was told to put in the transfer note. This is the
            // string a reviewer matches against the bank statement.
            'reference' => 'SUB-'.$this->id,
            'shop' => [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
                'slug' => $this->tenant?->slug,
                'owner_email' => $this->tenant?->owner_email,
                'owner_phone' => $this->tenant?->owner_phone,
            ],
            'plan' => $this->plan,
            'plan_label' => PlanCatalog::labelFor($this->plan),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            // Null means the shop asked for bank details and never uploaded
            // anything — worth chasing, not worth hiding.
            'proof_url' => $this->proof_path ? Storage::disk('public')->url($this->proof_path) : null,
            'reviewed_at' => $this->reviewed_at,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
