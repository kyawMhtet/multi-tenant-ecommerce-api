<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per payment method a shop has configured. */
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
     * Derived from the absence of a gateway rather than stored, so the two
     * can't contradict: no processor means no webhook is coming, which is
     * exactly what "manual" means.
     */
    public function isManual(): bool
    {
        return blank($this->gateway);
    }

    /** Ordered as the shop chose, so checkout renders it without re-sorting. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true)->orderBy('sort_order');
    }
}
