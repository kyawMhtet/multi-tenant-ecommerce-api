<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'currency' => $this->currency,
            // Wall-clock times with no zone of their own.
            'timezone' => $this->timezone,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'address' => $this->address,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
            'allows_delivery' => (bool) $this->allows_delivery,
            'allows_pickup' => (bool) $this->allows_pickup,
            // Public: the customer must see it BEFORE committing, not
            // discover it in the total at the end.
            'delivery_fee' => $this->delivery_fee,
            'business_hours' => $this->settings['business_hours'] ?? null,
            'social_links' => $this->settings['social_links'] ?? null,
            // Deprecated alias for the receipt template; remove once the
            // frontend reads business_phone. Deliberately NOT mirrored in
            // PublicShopResource — see there.
            'phone' => $this->business_phone ?? $this->owner_phone,
        ];
    }
}
