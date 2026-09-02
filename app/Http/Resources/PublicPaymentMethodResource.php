<?php

namespace App\Http\Resources;

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * `gateway` is NOT exposed: which processor backs a method is the shop's
 * commercial arrangement, and publishing it leaks which providers this
 * platform integrates with. `config` is withheld wholesale — whitelisting
 * keys later beats exposing the bag and hoping nothing sensitive lands in it.
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
            // So the storefront knows whether to show a screenshot upload,
            // without carrying its own copy of the catalogue.
            'requires_proof' => PaymentMethodCatalog::supportsProof($this->method),
        ], fn ($value) => $value !== null);
    }
}
