<?php

namespace App\Services;

use App\Models\Concerns\TenantScope;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Rules\NotReservedSlug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(private readonly ImageUploadService $imageUploadService) {}

    /**
     * Builds the tenant's product listing with optional, combinable
     * filters. No manual tenant_id filtering anywhere below — Product's and
     * ProductVariant's own BelongsToTenant/TenantScope apply automatically,
     * including inside the whereHas() sub-queries, since this always runs
     * with a tenant already bound (behind auth:sanctum + tenant
     * middleware).
     */
    public function listProducts(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Product::with('variants.images', 'images');

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            // Grouped into one closure, not two top-level where()/
            // orWhereHas() calls: a bare top-level orWhereHas() would OR
            // against every other filter on the query instead of ANDing
            // with them, e.g. silently turning category_id=5&search=foo
            // into "category 5 OR name matches foo". Matches barcode as
            // well as sku: a POS barcode scanner just types the scanned
            // value into this same search box and submits it, so an exact
            // scan needs to resolve a product the same way a typed SKU does.
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('variants', function ($vq) use ($search) {
                        $vq->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if (array_key_exists('category_id', $filters)) {
            $query->where('category_id', $filters['category_id']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['low_stock'])) {
            $query->whereHas('variants', fn ($vq) => $vq->lowStock());
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Creates a product and its first variant together.
     *
     * This is one transaction, not two separate creates, because a Product
     * with zero variants is an invalid state for this app: every price/SKU/
     * stock lookup elsewhere assumes at least one variant exists. If the
     * variant insert failed after the product insert succeeded (a bad SKU,
     * a dropped connection), we'd be left with a half-formed product that
     * every other query has to defensively handle. Wrapping both in a
     * transaction means either the whole thing lands or none of it does.
     */
    public function createProduct(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create(Arr::except($data, ['variant', 'images']));

            $this->addVariant($product, $data['variant']);

            if (! empty($data['images'])) {
                $this->addImages($product, $data['images']);
            }

            return $product->load('variants.images', 'images');
        });
    }

    /**
     * Adds a variant to an existing product, recording any starting stock
     * on the ledger (stock_movements) rather than only setting the
     * current_stock counter — current_stock is a denormalized cache, and an
     * uncounted starting balance would make it impossible to reconcile
     * stock later. Variant creation and the ledger entry are one
     * transaction for the same reason createProduct() is: a variant that
     * claims to have stock with no corresponding movement row is a bug.
     */
    public function addVariant(Product $product, array $variantData): ProductVariant
    {
        return DB::transaction(function () use ($product, $variantData) {
            $variant = $this->createVariantRow($product, Arr::except($variantData, ['images']));

            if ($variant->track_stock && $variant->current_stock > 0) {
                StockMovement::create([
                    'product_variant_id' => $variant->id,
                    'type' => 'initial',
                    'quantity' => $variant->current_stock,
                    'unit_cost' => $variant->buying_price,
                    'balance_after' => $variant->current_stock,
                    'created_by' => auth()->id(),
                ]);
            }

            // Last on purpose, same reasoning as createProduct()'s own
            // addImages() call: addVariantImages() writes files before
            // their DB rows and can only clean up its own partial
            // failures, not anything that fails after it.
            if (! empty($variantData['images'])) {
                $this->addVariantImages($variant, $variantData['images']);
            }

            return $variant->load('images');
        });
    }

    /**
     * The ownership check exists for the same reason deleteImage()'s does:
     * both the {product} and {variant} route parameters resolve
     * independently — TenantScope already rules out either one belonging
     * to a different tenant, but nothing ties them to EACH OTHER. Without
     * this, a tenant could edit variant #5 (really attached to their own
     * product #12) via a URL naming a different one of their own
     * products, e.g. /products/3/variants/5 — a same-tenant
     * URL/resource-mismatch bug, not a cross-tenant leak. 404, not 403,
     * matching the same belongs-to-someone-else-looks-like-doesn't-exist
     * choice used everywhere else in this app.
     *
     * current_stock is never in $data — UpdateProductVariantRequest
     * doesn't validate it, so there's nothing here to strip; stock only
     * ever changes through StockService's ledgered methods.
     *
     * Field update, removals, and additions are one transaction, same
     * reasoning as updateProduct(): a failure partway through must not
     * leave the field change committed with a removal or addition only
     * half-applied. addVariantImages() runs last for the same reason
     * addImages() does on the product side — see that method's docblock.
     */
    public function updateVariant(Product $product, ProductVariant $variant, array $data): ProductVariant
    {
        abort_unless($variant->product_id === $product->id, 404, 'Variant not found.');

        return DB::transaction(function () use ($variant, $data) {
            $variant->update(Arr::except($data, ['images', 'remove_image_ids']));

            if (! empty($data['remove_image_ids'])) {
                $variant->images()
                    ->whereIn('id', $data['remove_image_ids'])
                    ->get()
                    ->each(fn (ProductImage $image) => $this->deleteVariantImage($variant, $image));
            }

            if (! empty($data['images'])) {
                $this->addVariantImages($variant, $data['images']);
            }

            return $variant->fresh('images');
        });
    }

    /**
     * Product fields plus, optionally, more images to add and/or specific
     * ones to remove — both act only on what's actually present in
     * $data, same as the plain field update. Removals are re-fetched
     * through $product->images() here rather than trusting the ids in
     * $data directly: UpdateProductRequest already validated each one
     * belongs to this product, but the service doesn't assume that
     * check ran or was correct, the same reasoning OrderService
     * re-fetches cart lines by id instead of trusting validated() alone.
     * Each removal goes through deleteImage() so that logic (and its
     * commit-deferred file cleanup) has exactly one implementation, not
     * two.
     *
     * All three steps are one transaction: this now does in one request
     * what used to be separate, independent endpoints, so a failure in
     * addImages() (e.g. a corrupt file — see the Pest test for exactly
     * this) must not leave the field update or a removal already
     * committed with no way for the caller to know the request only
     * half-succeeded. addImages() already opens its own DB::transaction
     * internally; Laravel composes nested calls via a savepoint, not a
     * separate commit, so this nests safely.
     *
     * addImages() runs last on purpose: it writes files before their DB
     * rows, cleaning up only if ITS OWN loop fails partway (see its own
     * docblock) — it has no way to undo a write if something else later
     * in this same transaction fails instead. Since nothing currently
     * runs after it here, that gap never opens; moving it earlier would
     * reopen it, so don't reorder these three steps without reconsidering
     * that.
     */
    public function updateProduct(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update(Arr::except($data, ['images', 'remove_image_ids']));

            if (! empty($data['remove_image_ids'])) {
                $product->images()
                    ->whereIn('id', $data['remove_image_ids'])
                    ->get()
                    ->each(fn (ProductImage $image) => $this->deleteImage($product, $image));
            }

            if (! empty($data['images'])) {
                $this->addImages($product, $data['images']);
            }

            return $product;
        });
    }

    public function deleteProduct(Product $product): void
    {
        $product->delete();
    }

    /**
     * The ownership check (does this image actually belong to this
     * product?) matters because both route parameters are independently,
     * implicitly bound — Laravel's tenant scope already guarantees
     * $image belongs to the current tenant, but not that it belongs to
     * *this* product. Without it, a tenant could delete image #5 (which
     * is really attached to their own product #12) via a URL naming a
     * completely different one of their own products, e.g.
     * /products/3/images/5. 404, not 403: this is the same "don't
     * distinguish belongs-to-someone-else from doesn't-exist" choice
     * used everywhere else in this app.
     *
     * The row is deleted immediately, but the file only once the
     * surrounding transaction actually commits (DB::afterCommit()) —
     * deliberately not a synchronous "delete the file, then the row."
     * deleteImage() is now only ever called from inside
     * updateProduct()'s transaction alongside other steps (a field
     * update, possibly addImages() too); if one of those *other* steps
     * failed and rolled everything back, a synchronous file delete here
     * would already be done and wouldn't roll back with it — leaving the
     * (correctly restored) row pointing at a file that's gone. Deferring
     * the actual delete until the transaction is known to have committed
     * keeps the row and its file consistent in every outcome, not just
     * the successful one. Outside a transaction, afterCommit() just runs
     * immediately, so this is a strict improvement, not a behavior
     * change for the case where nothing else in the same call can fail.
     */
    public function deleteImage(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 404, 'Image not found.');

        $path = $image->path;
        $image->delete();

        DB::afterCommit(fn () => $this->imageUploadService->delete($path));
    }

    /**
     * Same ownership-check and deferred-file-delete reasoning as
     * deleteImage() — see its docblock — checked against
     * product_variant_id instead of product_id.
     */
    public function deleteVariantImage(ProductVariant $variant, ProductImage $image): void
    {
        abort_unless($image->product_variant_id === $variant->id, 404, 'Image not found.');

        $path = $image->path;
        $image->delete();

        DB::afterCommit(fn () => $this->imageUploadService->delete($path));
    }

    /**
     * Stores and optimizes each file (see ImageUploadService), then
     * records it — sort_order continues from whatever images already
     * exist, so images added later always sort after earlier ones rather
     * than colliding at 0.
     *
     * Row-locked the same way StockService::deductForSale() locks a
     * variant: without it, two concurrent calls for the same product
     * (e.g. two near-simultaneous update requests) could both read the
     * same max(sort_order) and write colliding values — not a tenant
     * leak, just a display-order correctness bug, but the fix is the
     * same shape already established elsewhere in this app.
     *
     * File writes aren't part of the DB transaction — they can't be, disk
     * I/O doesn't roll back — so a later failure in this loop (or
     * anything else in createProduct()'s outer transaction) would
     * otherwise leave orphaned files on disk with no DB row pointing at
     * them once the transaction rolls back. The try/catch exists
     * specifically to undo those already-written files before
     * re-throwing, so a rollback can't leave that behind.
     */
    public function addImages(Product $product, array $files): void
    {
        DB::transaction(function () use ($product, $files) {
            $locked = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            // max() returns null (not 0) when the product has no images
            // yet, and null + 1 in PHP is 1, not 0 — that off-by-one
            // would skip sort_order 0 entirely on a product's first image.
            $existingMax = $locked->images()->max('sort_order');
            $nextSortOrder = $existingMax === null ? 0 : $existingMax + 1;

            $storedPaths = [];

            try {
                foreach ($files as $index => $file) {
                    $path = $this->imageUploadService->store($file, 'products/'.$product->tenant_id);
                    $storedPaths[] = $path;

                    ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $path,
                        'sort_order' => $nextSortOrder + $index,
                    ]);
                }
            } catch (\Throwable $e) {
                foreach ($storedPaths as $path) {
                    $this->imageUploadService->delete($path);
                }

                throw $e;
            }
        });
    }

    /**
     * Same purpose, locking, and rollback shape as addImages() — see its
     * docblock — scoped to one variant's own images instead of the
     * product's general ones. Kept as a separate method rather than a
     * parameter on addImages(): the two write to different "buckets" (see
     * Product::images() vs. ProductVariant::images()'s whereNull split),
     * and one method juggling both would make it easier to accidentally
     * write a variant photo into the general gallery or vice versa.
     * product_id is still set alongside product_variant_id — an image is
     * still "of" the product, just also scoped to one of its variants.
     */
    public function addVariantImages(ProductVariant $variant, array $files): void
    {
        DB::transaction(function () use ($variant, $files) {
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            $existingMax = $locked->images()->max('sort_order');
            $nextSortOrder = $existingMax === null ? 0 : $existingMax + 1;

            $storedPaths = [];

            try {
                foreach ($files as $index => $file) {
                    $path = $this->imageUploadService->store($file, 'products/'.$variant->tenant_id);
                    $storedPaths[] = $path;

                    ProductImage::create([
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'path' => $path,
                        'sort_order' => $nextSortOrder + $index,
                    ]);
                }
            } catch (\Throwable $e) {
                foreach ($storedPaths as $path) {
                    $this->imageUploadService->delete($path);
                }

                throw $e;
            }
        });
    }

    /**
     * The pre-check in generateVariantSlug() narrows the odds of a
     * collision but isn't atomic — two concurrent requests could both pass
     * it with the same candidate before either commits. The unique index
     * on product_variants.slug is the real backstop, so this retries the
     * actual insert (not just the pre-check) on a genuine collision at the
     * database level, catching only that specific failure and re-raising
     * anything else unchanged.
     */
    private function createVariantRow(Product $product, array $variantData): ProductVariant
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                // Explicit defaults, not left to the DB column default: an
                // unset attribute after create() stays null in memory until
                // the model is re-fetched, so the track_stock check in
                // addVariant() would silently see null instead of the DB's
                // true and skip logging any starting stock.
                return $product->variants()->create(array_merge([
                    'track_stock' => true,
                    'current_stock' => 0,
                    // System-generated, like order_number — never accepted
                    // from the request, so array_merge's $variantData
                    // (which never contains a 'slug' key, since
                    // StoreProductRequest doesn't validate one) can't
                    // override it.
                    'slug' => $this->generateVariantSlug(),
                ], $variantData));
            } catch (QueryException $e) {
                $isSlugCollision = $e->getCode() === '23000' && str_contains($e->getMessage(), 'slug');

                if (! $isSlugCollision || $attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not create a product variant with a unique slug.');
    }

    /**
     * Random, not name-derived: a slug like "red-cotton-crew-neck-tshirt-l"
     * is barely shorter than the product name itself and defeats the point
     * of a short link, plus it goes stale the moment the product is
     * renamed (a shared link either breaks or silently points at a name
     * that no longer matches). A short random code stays constant-width
     * and never needs to change, at the cost of not being human-readable —
     * an acceptable trade for a link that exists to be pasted into a chat
     * app, not read aloud.
     *
     * Deliberately global, not per-tenant: bypassing TenantScope here is
     * one of the two sanctioned uses in this app (see CLAUDE.md) — a
     * uniqueness check scoped to "my tenant" would let two different
     * tenants land on the same slug, which breaks the public endpoint's
     * ability to resolve a tenant from the slug alone with no header.
     * Only TenantScope is stripped (not every global scope), so a
     * soft-deleted variant's slug still counts as taken.
     */
    private function generateVariantSlug(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = Str::lower(Str::random(8));

            // A reserved word is treated exactly like a uniqueness
            // collision — just try again, not an error — since this is
            // system-generated and the caller never sees the rejected
            // candidate. See NotReservedSlug for why this check exists
            // even though today's fixed 8-char length makes most reserved
            // words impossible to hit by chance.
            if (NotReservedSlug::isReserved($candidate)) {
                continue;
            }

            if (! ProductVariant::withoutGlobalScope(TenantScope::class)->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique product slug.');
    }
}
