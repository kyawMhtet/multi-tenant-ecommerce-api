<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * The lockForUpdate() re-fetch is the point: two concurrent sales could
     * otherwise both read current_stock = 1, both decide there's enough, and
     * sell the same last unit twice. The tenant scope stays ACTIVE here — the
     * caller already resolved the variant under it, so this is a targeted
     * re-fetch for locking, not a bypass.
     *
     * allow_preorder lifts the sufficiency check and lets current_stock go
     * negative. That IS the preorder mechanism: -7 means 7 units sold and
     * owed, receivePurchase() brings it back with no special case, and every
     * movement keeps a coherent balance_after. It's a permission, not a mode —
     * a preorder variant WITH stock deducts normally.
     */
    public function deductForSale(ProductVariant $variant, float $quantity, ?Model $reference = null): ProductVariant
    {
        return DB::transaction(function () use ($variant, $quantity, $reference) {
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            if (! $locked->track_stock) {
                return $locked;
            }

            if (! $locked->allow_preorder && $locked->current_stock < $quantity) {
                throw new InsufficientStockException($locked, $quantity);
            }

            $locked->decrement('current_stock', $quantity);

            StockMovement::create([
                'product_variant_id' => $locked->id,
                'type' => 'sale',
                'quantity' => -$quantity,
                'unit_cost' => $locked->buying_price,
                'balance_after' => $locked->current_stock,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->id,
                'created_by' => auth()->id(),
            ]);

            return $locked;
        });
    }

    /**
     * unit_cost, when given, logs what this batch actually cost AND updates
     * buying_price, so future margin is measured against real replacement
     * cost. When omitted it's left null rather than guessed from the old
     * price. Same row-lock reasoning as deductForSale().
     */
    public function receivePurchase(Product $product, ProductVariant $variant, float $quantity, ?float $unitCost = null, ?string $note = null): ProductVariant
    {
        abort_unless($variant->product_id === $product->id, 404, 'Variant not found.');

        return DB::transaction(function () use ($variant, $quantity, $unitCost, $note) {
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            $locked->increment('current_stock', $quantity, $unitCost !== null ? ['buying_price' => $unitCost] : []);

            StockMovement::create([
                'product_variant_id' => $locked->id,
                'type' => 'purchase',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'balance_after' => $locked->current_stock,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            return $locked;
        });
    }

    /**
     * Logged as 'return_in', distinct from 'purchase': both increment the same
     * counter but mean different things when reconciling the ledger later.
     */
    public function returnStock(ProductVariant $variant, float $quantity, ?Model $reference = null): ProductVariant
    {
        return DB::transaction(function () use ($variant, $quantity, $reference) {
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            if (! $locked->track_stock) {
                return $locked;
            }

            $locked->increment('current_stock', $quantity);

            StockMovement::create([
                'product_variant_id' => $locked->id,
                'type' => 'return_in',
                'quantity' => $quantity,
                'unit_cost' => $locked->buying_price,
                'balance_after' => $locked->current_stock,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->id,
                'created_by' => auth()->id(),
            ]);

            return $locked;
        });
    }
}
