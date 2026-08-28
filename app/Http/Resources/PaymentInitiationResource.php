<?php

namespace App\Http\Resources;

use App\Services\Payments\Data\PaymentInitiation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tells the storefront how to collect payment, in provider-agnostic terms.
 *
 * The client switches on `type` and never learns which gateway produced
 * this — that's the entire point of the abstraction reaching this far out.
 * Keys irrelevant to a given type are omitted rather than sent as null, so
 * the payload states only what actually applies.
 *
 * @property PaymentInitiation $resource
 */
class PaymentInitiationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_filter([
            'type' => $this->resource->type->value,
            'url' => $this->resource->url,
            'fields' => $this->resource->fields ?: null,
            'token' => $this->resource->token,
        ], fn ($value) => $value !== null);
    }
}
