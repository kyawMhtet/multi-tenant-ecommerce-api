<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-only. Never exposed on any public/storefront route: the courier's
 * phone number is the shop's operational contact for chasing a parcel, not
 * something a customer should be calling directly. A customer who needs to
 * track something gets the courier's NAME and the tracking number on the
 * order, which is enough to use the courier's own tracking page.
 */
class DeliveryProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'note' => $this->note,
            'sort_order' => $this->sort_order,
        ];
    }
}
