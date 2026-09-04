<?php

namespace App\Http\Resources;

use App\Services\Tenants\ShopRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => ShopRole::tryFrom((string) $this->role)?->label() ?? ucfirst((string) $this->role),
            'is_you' => $request->user()?->id === $this->id,
            'created_at' => $this->created_at,
        ];
    }
}
