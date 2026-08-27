<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'tenant_id' => $this->tenant_id,
            // The admin frontend needs this to build a public storefront
            // link for its own tenant (e.g. {tenant_slug}.{host}/{variant
            // slug}) — tenant_id alone doesn't let a client construct a
            // URL. Not a new exposure: a tenant's slug is already public,
            // sent right back by any client on every subsequent request
            // via X-Tenant-Slug.
            'tenant_slug' => $this->tenant?->slug,
        ];
    }
}
