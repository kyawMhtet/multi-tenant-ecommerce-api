<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan and subscription_status are deliberately NOT fillable: nothing in
 * this app writes either one (both rely on their DB column defaults), so
 * excluding them costs nothing and permanently forecloses a naive
 * $tenant->update($request->validated()) granting itself a paid plan.
 * slug and is_active remain fillable only because AuthService::register()
 * sets them — see the follow-up note in the shop-profile plan.
 */
#[Fillable([
    'name', 'slug', 'owner_name', 'owner_email', 'owner_phone',
    'logo_path', 'cover_path', 'address', 'business_phone', 'business_email',
    'trial_ends_at', 'subscription_ends_at', 'currency', 'stripe_account_id', 'allows_delivery', 'allows_pickup', 'settings', 'is_active',
])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'allows_delivery' => 'boolean',
            'allows_pickup' => 'boolean',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(TenantPaymentMethod::class);
    }
}
