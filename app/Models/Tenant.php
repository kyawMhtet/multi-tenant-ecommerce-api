<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Nothing about plans or subscription state lives on this model any more.
 * The plan/subscription_status/trial_ends_at/subscription_ends_at columns
 * were dropped in favour of the `subscriptions` table, which is the single
 * source of truth — holding the same fact in two places would mean a shop's
 * abilities depended on which one the enforcement code happened to read.
 *
 * That relocation also strengthens the old guarantee rather than weakening
 * it: those columns were kept out of $fillable so a naive
 * $tenant->update($request->validated()) could never grant a paid plan.
 * Now the fields are not on this model at all, so the shop-profile endpoint
 * has nothing to reach for even by accident.
 *
 * slug and is_active remain fillable only because AuthService::register()
 * sets them — see the follow-up note in the shop-profile plan.
 */
#[Fillable([
    'name', 'slug', 'owner_name', 'owner_email', 'owner_phone',
    'logo_path', 'cover_path', 'address', 'business_phone', 'business_email',
    'currency', 'timezone', 'stripe_account_id', 'allows_delivery', 'allows_pickup', 'delivery_fee', 'settings', 'is_active',
])]
class Tenant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'allows_delivery' => 'boolean',
            'allows_pickup' => 'boolean',
            'delivery_fee' => 'decimal:2',
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

    /**
     * hasOne, enforced by a unique index on subscriptions.tenant_id: "which
     * plan is this shop on" must be a question with exactly one answer.
     *
     * Not eager-loaded globally. It is read once per request and only when an
     * entitlement gate is actually consulted, which most requests never do.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }
}
