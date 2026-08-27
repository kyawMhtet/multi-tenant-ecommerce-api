<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The single definition of "what is public about a shop" — served both by
 * GET /api/v1/public/shop and embedded in StorefrontProductResource, so the
 * two public surfaces can't drift apart.
 *
 * Everything internal stays out: no id (slug is the public identifier, same
 * choice StorefrontProductVariantResource makes), no plan or
 * subscription_status, no trial/subscription dates, no owner_* fields, no
 * is_active, no timestamps.
 *
 * Note business_phone/business_email are the fields the owner explicitly
 * typed into the shop-profile form. They deliberately do NOT fall back to
 * owner_phone/owner_email the way TenantResource's admin-only 'phone' does:
 * those were given for an account, and publishing them on a public page is
 * the owner's consent decision, not a display default.
 */
class PublicShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'currency' => $this->currency,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'address' => $this->address,
            'business_phone' => $this->business_phone,
            'business_email' => $this->business_email,
            'business_hours' => $this->settings['business_hours'] ?? null,
            'social_links' => $this->settings['social_links'] ?? null,
        ];
    }
}
