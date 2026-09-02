<?php

namespace App\Services;

use App\Models\Concerns\TenantScope;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Rules\NotReservedSlug;
use App\Services\Billing\PlanFeature;
use App\Services\Billing\PlanGate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(
        private readonly ImageUploadService $imageUploadService,
        private readonly PlanGate $plans,
    ) {}

    public function listProducts(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Product::with('variants.images', 'images');

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            // One closure, not top-level orWhereHas(): a bare one would OR
            // against every other filter, turning category_id=5&search=foo into
            // "category 5 OR name matches foo". Barcode is matched too — a POS
            // scanner types into this same box.
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
     * One transaction: a Product with zero variants is an invalid state here,
     * since every price/SKU/stock lookup assumes at least one exists.
     */
    public function createProduct(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            // Counted inside the transaction, not before it: a check outside
            // would race two concurrent creates straight past the ceiling.
            // Product::count() is tenant-scoped by the global scope, so this
            // is this shop's catalogue and no other.
            //
            // The limit applies to CREATE only. A shop that drops onto a plan
            // it already exceeds keeps every product it has — nothing is
            // deleted or hidden to force an upgrade.
            $this->plans->ensureWithin('products', Product::count());

            $product = Product::create(Arr::except($data, ['variant', 'images']));

            $this->addVariant($product, $data['variant']);

            if (! empty($data['images'])) {
                $this->addImages($product, $data['images']);
            }

            return $product->load('variants.images', 'images');
        });
    }

    /**
     * Starting stock goes on the ledger, not just the current_stock counter:
     * that counter is a cache, and an uncounted starting balance makes stock
     * impossible to reconcile later.
     */
    public function addVariant(Product $product, array $variantData): ProductVariant
    {
        $this->ensureMayPreorder($variantData);

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

            // Last on purpose: it writes files before their DB rows and can
            // only clean up its own failures, not anything that fails after it.
            if (! empty($variantData['images'])) {
                $this->addVariantImages($variant, $variantData['images']);
            }

            return $variant->load('images');
        });
    }

    /**
     * {product} and {variant} bind independently — TenantScope rules out
     * either belonging to another tenant, but nothing ties them to EACH
     * OTHER, so /products/3/variants/5 could edit a variant of product 12.
     * 404, not 403, like everywhere else here.
     */
    public function updateVariant(Product $product, ProductVariant $variant, array $data): ProductVariant
    {
        abort_unless($variant->product_id === $product->id, 404, 'Variant not found.');

        $this->ensureMayPreorder($data);

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
     * Field edits, removals and additions in one transaction, so a client's
     * "Save changes" can't half-succeed. Removals are re-fetched rather than
     * trusting the validated ids.
     *
     * addImages() runs LAST on purpose: it writes files before their DB rows
     * and can't undo them if something later in the transaction fails. Don't
     * reorder these three steps without re-examining that.
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
     * Ownership check for the same reason as updateVariant()'s.
     *
     * The file is deleted via afterCommit, not synchronously: file I/O can't
     * roll back, so a rollback later in updateProduct()'s transaction would
     * restore the row while the file it points at stayed gone. This is the
     * pattern for any non-transactional side effect.
     */
    public function deleteImage(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 404, 'Image not found.');

        $path = $image->path;
        $image->delete();

        DB::afterCommit(fn () => $this->imageUploadService->delete($path));
    }

    /** Same reasoning as deleteImage(), keyed on product_variant_id. */
    public function deleteVariantImage(ProductVariant $variant, ProductImage $image): void
    {
        abort_unless($image->product_variant_id === $variant->id, 404, 'Image not found.');

        $path = $image->path;
        $image->delete();

        DB::afterCommit(fn () => $this->imageUploadService->delete($path));
    }

    /**
     * Row-locked so two concurrent calls can't read the same max(sort_order)
     * and write colliding values.
     *
     * File writes can't join the transaction, so the try/catch deletes any
     * already-written files before re-throwing — otherwise a rollback leaves
     * orphans on disk with no row pointing at them.
     */
    public function addImages(Product $product, array $files): void
    {
        DB::transaction(function () use ($product, $files) {
            $locked = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            // max() is null on the first image, and null + 1 is 1 — that
            // off-by-one would skip sort_order 0 entirely.
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
     * Same shape as addImages(), scoped to one variant. Separate rather than a
     * flag, since the two write to different buckets and one method juggling
     * both makes it easy to put a variant photo in the general gallery.
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
     * generateVariantSlug()'s pre-check isn't atomic — two concurrent requests
     * can pass it with the same candidate. The unique index is the real
     * backstop, so this retries the INSERT, not just the pre-check.
     */
    /**
     * Checked here rather than in the Form Request, because allow_preorder is
     * one field on a request that does plenty else. A route-level gate would
     * reject the shop's entire product edit with a billing error; this
     * refuses only when they actually asked to sell below zero.
     *
     * Only a truthy value is gated. Sending allow_preorder=false, or leaving
     * it out, is always allowed — turning a paid feature OFF must never
     * require the plan that turned it on, or a downgraded shop would be stuck
     * with it.
     */
    private function ensureMayPreorder(array $variantData): void
    {
        if (! empty($variantData['allow_preorder'])) {
            $this->plans->ensureFeature(PlanFeature::Preorder);
        }
    }

    private function createVariantRow(Product $product, array $variantData): ProductVariant
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                // Explicit defaults, not the DB's: an unset attribute stays null
                // in memory until re-fetched, so addVariant()'s track_stock check
                // would see null instead of true and skip the ledger row.
                return $product->variants()->create(array_merge([
                    'track_stock' => true,
                    'current_stock' => 0,
                    'allow_preorder' => false,
                    'preorder_requires_prepayment' => false,
                    // System-generated, never accepted from request input.
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
     * Random, not name-derived: a name-based slug goes stale the moment the
     * product is renamed, breaking every link already shared.
     *
     * The uniqueness check is deliberately GLOBAL — a sanctioned TenantScope
     * bypass (see CLAUDE.md). Scoped per tenant, two shops could land on the
     * same slug, which breaks the public endpoint's ability to resolve a
     * tenant from the slug alone. Only TenantScope is stripped, so a
     * soft-deleted variant's slug still counts as taken.
     */
    private function generateVariantSlug(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = Str::lower(Str::random(8));

            // Treated like a collision, not an error: this is
            // system-generated and the caller never sees the rejected value.
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
