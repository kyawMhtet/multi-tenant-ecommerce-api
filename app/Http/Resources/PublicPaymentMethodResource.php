<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A payment option as the customer sees it at checkout.
 *
 * `gateway` is deliberately NOT exposed. Which processor sits behind a
 * method is the shop's commercial arrangement, not the customer's
 * business, and publishing it would also leak which providers this
 * platform integrates with. `config` is withheld wholesale for the same
 * reason — whitelisting customer-relevant keys later is far safer than
 * exposing the bag and hoping nothing sensitive is added to it.
 *
 * The QR and instructions ARE public, necessarily: they're what the
 * customer scans and reads in order to pay. The shop uploaded them for
 * exactly this purpose.
 */
class PublicPaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_filter([
            'method' => $this->method,
            'label' => PaymentMethodCatalog::labelFor($this->method),
            'qr_url' => $this->qr_path ? Storage::disk('public')->url($this->qr_path) : null,
            'instructions' => $this->instructions,
            // Tells the storefront whether to show a screenshot upload on
            // the checkout form for this option, without the frontend
            // needing its own copy of the method catalogue.
            'requires_proof' => PaymentMethodCatalog::supportsProof($this->method),
        ], fn ($value) => $value !== null);
    }
}
