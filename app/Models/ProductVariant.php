<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Pricing\DiscountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_id', 'sku', 'slug', 'barcode', 'variant_name', 'attributes', 'unit',
    'buying_price', 'selling_price', 'discount_type', 'discount_value', 'discount_starts_at', 'discount_ends_at',
    'track_stock', 'current_stock', 'low_stock_threshold', 'is_active',
    'allow_preorder', 'preorder_lead_time_days', 'preorder_deposit_percent',
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
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_starts_at' => 'datetime',
            'discount_ends_at' => 'datetime',
            'track_stock' => 'boolean',
            'allow_preorder' => 'boolean',
            'preorder_deposit_percent' => 'integer',
            'preorder_lead_time_days' => 'integer',
            'current_stock' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether a discount is live RIGHT NOW.
     *
     * Derived from the dates rather than stored beside them, so the two can
     * never disagree and a shop doesn't have to remember to switch a
     * promotion off — the same reason billing grace is derived from
     * current_period_ends_at instead of being written down.
     *
     * The window is half-open: [starts_at, ends_at). An end of midnight on
     * the 10th means the 9th was the last day, which is what a shop setting
     * "until the 10th" in a date picker means. Null at either end is the
     * ordinary case — no start means live now, no end means until withdrawn.
     *
     * A zero value is not a discount, whatever the type says. That keeps
     * "0% off" out of the storefront's sale badge.
     */
    public function discountActive(): bool
    {
        if ($this->discount_type === null || (float) $this->discount_value <= 0) {
            return false;
        }

        $now = now();

        if ($this->discount_starts_at !== null && $now->lt($this->discount_starts_at)) {
            return false;
        }

        return $this->discount_ends_at === null || $now->lt($this->discount_ends_at);
    }

    /** Money off one unit, or 0.0 when nothing is running. */
    public function discountPerUnit(): float
    {
        return $this->discountActive()
            ? $this->discount_type->amountOff((float) $this->selling_price, (float) $this->discount_value)
            : 0.0;
    }

    /**
     * What this variant actually sells for today.
     *
     * Computed, never stored — one source of truth, and the reason a reprice
     * can't leave a stale discounted figure behind. OrderService is the only
     * thing that may turn this into money on an order; a price arriving in a
     * request body is a price the customer can set, the same rule that keeps
     * tenant_id and delivery_fee out of request input.
     */
    public function effectivePrice(): float
    {
        return round((float) $this->selling_price - $this->discountPerUnit(), 2);
    }

    /**
     * The storefront badge figure ("20% off"), rounded to a whole percent.
     *
     * Derived even for a fixed discount, so a client renders one badge rather
     * than branching on the type. Null when nothing is off — including on a
     * free item, where a percentage off zero is meaningless.
     */
    public function discountPercent(): ?int
    {
        $off = $this->discountPerUnit();
        $listPrice = (float) $this->selling_price;

        if ($off <= 0 || $listPrice <= 0) {
            return null;
        }

        return (int) round($off / $listPrice * 100);
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
