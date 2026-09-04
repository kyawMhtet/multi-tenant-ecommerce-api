<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_variant_id', 'product_name', 'variant_name', 'sku', 'attributes',
    'quantity', 'is_preorder', 'preorder_lead_time_days', 'deposit_amount',
    'unit_price', 'discount_amount', 'unit_cost', 'line_total',
])]
class OrderItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'quantity' => 'decimal:2',
            'is_preorder' => 'boolean',
            'deposit_amount' => 'decimal:2',
            'preorder_lead_time_days' => 'integer',
            'unit_price' => 'decimal:2',
            // Money off this line, snapshotted. unit_price stays the LIST
            // price, so unit_price * quantity - discount_amount = line_total.
            'discount_amount' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
