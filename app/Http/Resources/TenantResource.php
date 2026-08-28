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
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'address' => $this->address,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
            'allows_delivery' => (bool) $this->allows_delivery,
            'allows_pickup' => (bool) $this->allows_pickup,
            'business_hours' => $this->settings['business_hours'] ?? null,
            'social_links' => $this->settings['social_links'] ?? null,
            // Deprecated alias, kept so the existing receipt template doesn't
            // break. Falls back to owner_phone for tenants that predate the
            // shop-profile form — the same "for a small shop the owner's
            // phone IS the shop's phone" reasoning as before, now with a real
            // business column taking precedence when set. Deliberately NOT
            // mirrored in PublicShopResource: publishing a number the owner
            // only ever gave us for their account is a consent decision, not
            // a display one. Remove once the frontend reads business_phone.
            'phone' => $this->business_phone ?? $this->owner_phone,
        ];
    }
}
