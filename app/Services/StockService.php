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
     * Deducts stock for a sale and logs it on the ledger.
     *
     * Re-fetches the variant with lockForUpdate() rather than trusting the
     * instance passed in: two concurrent sales of the same variant could
     * otherwise both read current_stock = 1, both decide there's enough,
     * and both deduct — selling the same last unit twice. The row lock
     * forces the second transaction to wait until the first commits (or
     * rolls back), so it re-checks against the real post-deduction balance.
     * This is the one place CLAUDE.md allows bypassing the ambient
     * tenant-scoped query in favor of an explicit id lookup — the variant
     * was already resolved (and tenant-checked) by the caller, so this is
     * a targeted re-fetch for locking, not a way to skip tenant scoping.
     *
     * Untracked variants (track_stock = false, e.g. made-to-order items)
     * have nothing to decrement or reconcile, so they're a no-op here.
     */
    public function deductForSale(ProductVariant $variant, float $quantity, ?Model $reference = null): ProductVariant
    {
        return DB::transaction(function () use ($variant, $quantity, $reference) {
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            if (! $locked->track_stock) {
                return $locked;
            }

            if ($locked->current_stock < $quantity) {
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
     * The ownership check is the same reason updateVariant()'s exists in
     * ProductService — {product} and {variant} route parameters resolve
     * independently, so nothing else ties this variant to the product
     * named in the URL.
     *
     * unit_cost, when given, both logs the actual price paid on this
     * delivery (the ledger is the permanent, per-batch record — see
     * CLAUDE.md on snapshotting unit_cost rather than trusting today's
     * price) AND updates the variant's own buying_price, so future sales'
     * margin is measured against the real replacement cost rather than a
     * stale one. When omitted (the caller doesn't know/didn't record this
     * batch's cost), buying_price is left untouched and the movement's
     * own unit_cost stays null — never guessed from the old buying_price.
     *
     * Same row-lock as deductForSale() and the same reasoning: two
     * concurrent restocks of the same variant must not both read a stale
     * current_stock and stomp on each other's increment.
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
     * Credits stock back for a cancelled order, logged as 'return_in' —
     * distinct from 'purchase' (new stock coming in from a supplier) even
     * though both increment the same counter, because they mean different
     * things when reconciling the ledger later. Untracked variants
     * (track_stock = false) are a no-op, same as deductForSale() — nothing
     * to reconcile for a variant with no meaningful stock count. Same
     * row-lock/re-fetch reasoning as the other two StockService methods.
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
