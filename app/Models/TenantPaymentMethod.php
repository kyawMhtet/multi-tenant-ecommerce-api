<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per payment method a shop has configured.
 *
 * tenant_id is excluded from $fillable on purpose, same as every other
 * tenant-owned model here — BelongsToTenant's creating hook fills it, so
 * it can never be set from request input.
 */
#[Fillable(['method', 'gateway', 'qr_path', 'instructions', 'is_enabled', 'sort_order', 'config'])]
class TenantPaymentMethod extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether this method collects payment out-of-band and needs a human to
     * confirm it — cash on delivery, or a bank/wallet transfer against the
     * shop's own QR. Derived from the absence of a gateway rather than
     * stored, so the two can never contradict each other: no processor
     * means nobody is going to send us a webhook, which is exactly what
     * "manual" means.
     */
    public function isManual(): bool
    {
        return blank($this->gateway);
    }

    /**
     * What the storefront is allowed to offer. Ordered the way the shop
     * chose, so checkout can render the list directly without re-sorting.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true)->orderBy('sort_order');
    }
}
