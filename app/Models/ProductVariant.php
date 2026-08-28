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

    /**
     * Photos specific to this variant (e.g. this color) — separate from
     * Product::images()'s general gallery. Optional: a variant with no
     * photos of its own simply has an empty collection here, and the
     * frontend decides whether to fall back to the product's general
     * images — that's a display choice, not something baked into the API.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * The single definition of "low stock" — shared by DashboardService's
     * count and the products filter, so the two can never quietly disagree
     * about what counts as low. track_stock=false variants are excluded on
     * purpose: they don't carry a meaningful stock number at all.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('current_stock', '<=', 'low_stock_threshold');
    }
}
