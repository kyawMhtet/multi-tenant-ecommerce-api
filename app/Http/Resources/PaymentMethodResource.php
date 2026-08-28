<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-facing view of a configured payment method. Unlike the public
 * counterpart this DOES expose `gateway` — the shop is entitled to know
 * whether a method routes through a processor or waits for them to confirm
 * it by hand, since that's the difference between money arriving on its own
 * and money needing a human.
 */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'method' => $this->method,
            'label' => PaymentMethodCatalog::labelFor($this->method),
            'gateway' => $this->gateway,
            'is_manual' => $this->isManual(),
            'is_enabled' => $this->is_enabled,
            'sort_order' => $this->sort_order,
            'instructions' => $this->instructions,
            'qr_url' => $this->qr_path ? Storage::disk('public')->url($this->qr_path) : null,
            // Lets the settings UI decide which fields to show without
            // hardcoding a copy of the catalogue in the frontend.
            'supports_qr' => PaymentMethodCatalog::supportsQr($this->method),
            'supports_proof' => PaymentMethodCatalog::supportsProof($this->method),
        ];
    }
}
