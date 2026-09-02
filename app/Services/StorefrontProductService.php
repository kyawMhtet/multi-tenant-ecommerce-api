<?php

namespace App\Services;

use App\Models\Concerns\TenantScope;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StorefrontProductService
{
    /**
     * Unlike findPublicVariant(), a tenant is already bound here (the
     * storefront sends X-Tenant-Slug), so this is an ordinary scoped query.
     *
     * Active-only on both product and variants: a product whose variants are
     * ALL inactive is excluded entirely, not shown with an empty list.
     */
    public function listPublicProducts(array $filters): LengthAwarePaginator
    {
        $query = Product::where('is_active', true)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true))
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true)->with('images'),
                'images',
            ]);

        // Name only, not sku/barcode — those are internal identifiers.
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        if (array_key_exists('category_id', $filters)) {
            $query->where('category_id', $filters['category_id']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 12);
    }

    /**
     * Resolves a public product page from the slug alone — no header, no
     * subdomain — since this has to work as a link pasted into a chat app.
     *
     * No tenant is bound yet (that's the point), so TenantScope would no-op
     * anyway; the explicit bypass makes that deliberate rather than an
     * accident of timing. A sanctioned exception, see CLAUDE.md. Never
     * withoutGlobalScopes(), which would make soft-deleted variants resolve.
     *
     * is_active is checked on variant, product AND tenant — a "hide this"
     * toggle at any level must 404, not return a flag.
     */
    public function findPublicVariant(string $slug): ProductVariant
    {
        $variant = ProductVariant::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('is_active', true))
            ->whereHas('tenant', fn ($query) => $query->where('is_active', true))
            ->with([
                // Nested bypasses for the same reason: still no tenant bound.
                'product.variants' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('is_active', true)
                    ->with(['images' => fn ($imageQuery) => $imageQuery->withoutGlobalScope(TenantScope::class)]),
                'product.images' => fn ($query) => $query->withoutGlobalScope(TenantScope::class),
                // Renders the shop header — see StorefrontProductResource.
                'product.tenant',
            ])
            ->firstOrFail();

        // Bound explicitly so anything touched later in this request behaves
        // like every other tenant-aware route, rather than silently skipping
        // scoping because nothing happened to be bound.
        app()->instance('tenant', $variant->tenant);

        return $variant;
    }
}
