<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_id', 'sku', 'slug', 'barcode', 'variant_name', 'attributes', 'unit',
    'buying_price', 'selling_price', 'track_stock', 'current_stock', 'low_stock_threshold', 'is_active',
    'allow_preorder', 'preorder_lead_time_days', 'preorder_requires_prepayment',
])]
class ProductVariant extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'buying_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'track_stock' => 'boolean',
            'allow_preorder' => 'boolean',
            'preorder_lead_time_days' => 'integer',
            'preorder_requires_prepayment' => 'boolean',
            'current_stock' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Photos specific to this variant, separate from the general gallery. */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * The single definition of "low stock", shared by the dashboard and the
     * products filter. Untracked variants have no meaningful number.
     *
     * Negative stock is excluded deliberately: -7 isn't "running low", it's
     * oversold — a different problem with a different fix. scopeOversold()
     * reports those, so every variant lands in exactly one bucket.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)
            ->where('current_stock', '>=', 0)
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('current_stock', '<=', 'low_stock_threshold');
    }

    /**
     * The preorder backlog. Deliberately NOT filtered on allow_preorder: units
     * already sold are still owed even if the shop unticks the box, and the
     * negative balance is the fact — the flag was only the permission.
     */
    public function scopeOversold(Builder $query): Builder
    {
        return $query->where('track_stock', true)->where('current_stock', '<', 0);
    }
}
