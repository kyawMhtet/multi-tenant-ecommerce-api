# Myanmar Shop SaaS — POS + Storefront

Multi-tenant SaaS for small shops in Myanmar: a POS for in-store sales and an online storefront,
sharing one catalog/inventory/order backend per tenant.

## Stack
- API: Laravel, Sanctum for auth (not yet wired up)
- Frontend: separate Next.js app, route groups `(storefront)` and `(admin)`, tenant resolved by
  subdomain in `middleware.ts` (not yet built)
- Database: MySQL

## Non-negotiable: tenant isolation
- Every tenant-owned model uses the `BelongsToTenant` trait in
  `app/Models/Concerns/BelongsToTenant.php`
- Never manually add `->where('tenant_id', ...)` — the global scope already does this
- Never bypass `TenantScope` outside the two sanctioned cases in `ProductService` and
  `StorefrontProductService` (see Known constraints) — anywhere else it needs the same scrutiny
  as taking `tenant_id` from request input would. Always bypass it precisely
  (`withoutGlobalScope(TenantScope::class)`), never with the blanket `withoutGlobalScopes()` —
  the blanket form also disables `SoftDeletingScope` on soft-deletable models, silently making
  soft-deleted rows resolvable again
- Never take `tenant_id` from request input. It always comes from the authenticated tenant
  context via `ResolveTenant` middleware (not yet built — currently resolved via the `'tenant'`
  container binding or `auth()->user()->tenant_id`, see `TenantScope::currentTenantId()`)
- Every new tenant-scoped resource needs a Pest test proving tenant A's data is invisible to
  tenant B. Not optional.

## Known constraints
- `ResolveTenant`'s isolation guarantee currently depends on authentication. An unauthenticated
  route behind `tenant` middleware alone (e.g. public storefront browsing) only guarantees "the
  slug exists and is active" — fine for read-only catalog access, but any write action on such a
  route (like guest checkout) needs its own explicit ownership check, not just the tenant
  middleware.
- `AuthService`'s email lookup is global across all tenants and is only safe because
  `users.email` has a database-level unique constraint. If that constraint is ever relaxed to
  per-tenant-unique emails, the lookup must take a tenant identifier as an additional filter
  first. Flag this immediately if that schema change ever comes up.
- `deductForSale()`'s row-lock re-fetch intentionally keeps the tenant scope active (no bypass at
  all) — this is correct for request-scoped calls, since the variant was already resolved under
  the current tenant earlier in the same request.
- Bypassing `TenantScope` (always via `withoutGlobalScope(TenantScope::class)`, never the
  blanket `withoutGlobalScopes()` — see the non-negotiable rule above) has exactly two sanctioned
  uses today, both deliberate and request-scoped:
  - `ProductService::generateVariantSlug()`'s uniqueness check searches across all tenants on
    purpose, since `product_variants.slug` is globally unique by design (see Product/variant
    model) — a per-tenant-scoped check would let two different tenants land on the same slug.
    `ProductService::createVariantRow()` also retries the actual insert (not just this pre-check)
    on a genuine DB-level collision, since the pre-check alone isn't atomic under concurrent
    requests.
  - `StorefrontProductService::findPublicVariant()` resolves a public link with no tenant bound
    in the container yet at all. The scope would silently no-op there regardless (no tenant
    context = no filter), so the explicit bypass exists to make that behavior deliberate rather
    than an accident of timing.
  A future background job (e.g. a cross-tenant reconciliation job) would be a third legitimate
  case, but it must take `tenant_id` explicitly rather than relying on any container binding.
- Both `ResolveTenant` and `StorefrontProductService::findPublicVariant()` bind the resolved
  tenant via `app()->instance('tenant', ...)` and never explicitly forget it. Harmless under the
  traditional PHP-FPM model this app currently runs on (the container dies with the request), but
  it's a real cross-request tenant-context leak waiting to happen the moment Octane or a
  persistent queue worker is adopted — a long-running worker process doesn't get a fresh
  container between requests/jobs the way PHP-FPM does. Treat adding an explicit
  `forgetInstance('tenant')` teardown (e.g. on `RequestTerminated`/`JobProcessed`, or via
  Octane's state-flushing hooks) as a blocking prerequisite of adopting either, not an
  afterthought — don't build that plumbing before it's actually needed.

## Product/variant model
- `Product` is display-only (name, description, category, image). Every price, SKU, and stock
  figure lives on `ProductVariant`.
- Even a simple product with no real options gets exactly one variant row.
- `product_variants.slug` is a system-generated, globally unique (not per-tenant) short random
  code, auto-set by `ProductService::generateVariantSlug()` — never accepted from request input.
  It powers the public storefront link (`GET /api/v1/public/products/{slug}`), which resolves a
  tenant from the slug alone with no header or subdomain needed. See
  `ProductService::generateVariantSlug()` and `StorefrontProductService`'s docblocks for the
  reasoning (random vs. sequential, and why the lookup is global).
- `product_variants.slug` is checked against `App\Rules\NotReservedSlug::RESERVED` (cart,
  checkout, login, admin, api, etc. — words the storefront frontend needs at its own URL root)
  before it's accepted. Enforced in `ProductService::generateVariantSlug()` today, since slug is
  system-generated and there's no request-input path for it to validate — `NotReservedSlug` is
  built as a reusable `ValidationRule` specifically so it can be dropped into a Form Request with
  no rework if a custom/vanity slug field is ever added. No DB-level constraint: the reserved-word
  list is a frontend-routing decision that will change as the Next.js app grows, not a structural
  data invariant like `slug` uniqueness — coupling it to a migration would be the wrong layer for
  something this mutable.
- Product photos are a real upload, not a client-supplied URL string: `products.image_path` was
  removed in favor of a `product_images` table (one-to-many, `sort_order` determines display
  order — the lowest is the cover image). `ImageUploadService` is the only thing that writes a
  `path` — it re-encodes every upload as JPEG at quality 80 and caps the longest side at 1600px
  (`scaleDown`, so it only ever shrinks, never upscales) before storing it on the `public` disk.
  `StoreProductRequest`/`UpdateProductRequest` validate `images.*` as `image`/`max:2048` (KB);
  `ProductService::addImages()` is shared by create (first upload) and update (appends — never
  replaces existing images). Resources never return the raw storage-relative path — always
  `Storage::disk('public')->url(...)`, so nothing outside `ImageUploadService` needs to know which
  disk or URL prefix it lives under.
- Removing a product image is folded into `UpdateProductRequest` (`remove_image_ids: number[]`),
  not a separate endpoint — deliberately, so a client's "Save changes" always submits field edits
  plus additions plus removals as one atomic request rather than several independent calls the
  UI has to sequence and partially recover from. `updateProduct()` wraps all three in one
  `DB::transaction()`. `ProductService::deleteImage()` defers the actual file removal via
  `DB::afterCommit()` rather than deleting synchronously — file I/O isn't transactional, so a
  synchronous delete followed by a rollback (e.g. a later step in the same request failing) would
  restore the DB row while the file it points at stays gone. This is the pattern to reach for
  any time a non-transactional side effect (file delete, external API call) needs to participate
  correctly in a DB transaction; `addImages()`'s file *writes* don't use it — deliberately,
  since it always runs last in both `createProduct()` and `updateProduct()`, so nothing after it
  in the same transaction can trigger a rollback once its files are written. Don't reorder those
  methods without re-examining that assumption.

## Stock and money
- All stock changes go through `app/Services/StockService.php` (`deductForSale`,
  `receivePurchase`) — not yet built. Never decrement `current_stock` directly from a controller;
  every change must also insert a `stock_movements` row (see `database/migrations/*_create_stock_movements_table.php`
  for why it's a ledger, not just a counter).
- Money columns are `decimal(12,2)`, never float.
- `order_items.unit_price` and `unit_cost` are snapshotted at sale time. Margin reporting always
  uses this snapshot, never today's variant price.

## Architecture pattern
- Controllers (`app/Http/Controllers/Api/`) are thin: validate via a Form Request, call a
  Service, return a Resource. No business logic in controllers, ever.
- Business logic lives in `app/Services/`.
- API Resources (`app/Http/Resources/`) control exactly what each endpoint exposes — critical for
  keeping `buying_price`/`unit_cost` out of any storefront-facing response, since only admin
  should see cost data.

## Testing
- Pest, one test file per feature. Run `php artisan test` before considering any task done.

## Workflow
This project is being built learn-by-doing. Explain the reasoning behind a pattern before
implementing it, especially for tenant scoping, stock, or pricing.

## Current state (as of 2026-08-20)
- MySQL configured; all 10 core migrations applied (`tenants`, `users` +tenant/role,
  `categories`, `products`, `product_variants`, `stock_movements`, `customers`, `orders`,
  `order_items`, `payments`).
- `TenantScope` + `BelongsToTenant` in place and verified (auto-fill on create, cross-tenant
  read isolation).
- Not yet built: Sanctum auth, `ResolveTenant` middleware, `StockService`, Form Requests, API
  Resources, Pest tests, Next.js frontend.
