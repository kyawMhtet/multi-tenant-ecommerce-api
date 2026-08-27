<?php

namespace App\Services;

use App\Models\Concerns\TenantScope;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StorefrontProductService
{
    /**
     * Unlike findPublicVariant(), this runs with a tenant already bound —
     * the storefront homepage knows its own tenant from the subdomain it's
     * served on and sends X-Tenant-Slug like any other tenant-aware public
     * route (see routes/api.php), so this is a completely ordinary scoped
     * query with no TenantScope bypass.
     *
     * Always active-only, on both the product and every included variant:
     * a public catalog is not a place to toggle visibility, and a product
     * whose variants are ALL inactive is excluded entirely rather than
     * shown with an empty variants list.
     */
    public function listPublicProducts(array $filters): LengthAwarePaginator
    {
        $query = Product::where('is_active', true)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true))
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true),
                'images',
            ]);

        // Name only, not sku/barcode like the admin filter — those are
        // internal identifiers a customer has no reason to type into a
        // storefront search box.
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        if (array_key_exists('category_id', $filters)) {
            $query->where('category_id', $filters['category_id']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 12);
    }

    /**
     * Resolves a public product page from a variant slug alone — no tenant
     * header, no subdomain, nothing but the slug, since this is meant to
     * work as a plain link pasted into a chat app, which can't attach a
     * custom header the way an authenticated API client can.
     *
     * Bypassing TenantScope specifically (not withoutGlobalScopes(), which
     * strips every global scope including SoftDeletingScope) is required
     * here, not just harmless: before this call, no tenant is bound in the
     * container yet (that's the whole point — we don't have one to bind
     * until we've looked the variant up), so TenantScope would be a silent
     * no-op regardless. Making the bypass explicit means this route's
     * behavior doesn't depend on that no-op-when-absent coincidence — it's
     * a deliberate, documented exception (see CLAUDE.md), not an accident
     * of timing. Stripping every scope instead would also make a
     * soft-deleted variant resolvable here, which is never intended.
     *
     * is_active is checked on the variant, its product, and its tenant —
     * a shop owner's "hide this" toggle at any of those three levels must
     * make the item disappear from this endpoint entirely (404, not a
     * flag in the response), and an inactive tenant's storefront pages
     * must not resolve at all.
     */
    public function findPublicVariant(string $slug): ProductVariant
    {
        $variant = ProductVariant::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('is_active', true))
            ->whereHas('tenant', fn ($query) => $query->where('is_active', true))
            ->with([
                'product.variants' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('is_active', true),
                'product.images' => fn ($query) => $query->withoutGlobalScope(TenantScope::class),
                // The product page renders the shop's header (logo, name)
                // from this — see StorefrontProductResource's 'shop' key.
                'product.tenant',
            ])
            ->firstOrFail();

        // Bind the tenant explicitly rather than relying on the scope
        // staying a no-op for the rest of this request — anything touched
        // afterward (e.g. a future related lookup) should behave exactly
        // like it does on every other tenant-aware route, not silently
        // skip scoping because nothing happened to be bound yet.
        app()->instance('tenant', $variant->tenant);

        return $variant;
    }
}
