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
- **Registration issues NO token — creating a shop is not signing in.** `POST /register` returns
  the created account and nothing else; the owner then signs in at `/login` with the password
  they just chose. This keeps `login()` the only thing that mints tokens, proves the owner knows
  the credential rather than riding a session they never authenticated for, and is the seam email
  verification would need if it is ever added. A client that reads `token` off the 201 will store
  `undefined` and then 401 on everything while appearing signed in.
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
  - `WebhookProcessor::process()` resolves a payment webhook with no tenant bound at all — a
    gateway callback is server-to-server, carrying neither a token nor an `X-Tenant-Slug`
    header, so the tenant is *derived* from whichever `transaction_ref` resolves rather than
    known up front. Not a widened exposure: `transaction_ref` is provider-generated and
    recorded by us at session creation, and the signature check means only the provider can
    present one. Note it strips `TenantScope` only — `Order` is soft-deletable, and the
    blanket form would let a replayed webhook resurrect a deleted order and mark it paid.
  - `BillingWebhookProcessor::process()` and everything in `SubscriptionReviewService` resolve a
    SUBSCRIPTION with no tenant bound — a Stripe billing callback carries no token or header, and
    a platform admin has no tenant at all. Both strip the scope explicitly even though it would
    no-op anyway in those contexts, so cross-tenant access is a decision rather than an accident
    of who is logged in.
  A future background job (e.g. a cross-tenant reconciliation job) would be another legitimate
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
  **The queue half of this is now DONE** (`AppServiceProvider::forgetTenantBetweenJobs()`, on
  `Queue::before`/`after`/`failing`), because billing emails are queued. Cleared before as well
  as after: `after` alone leaves the first job of a worker's life exposed, and a job that dies in
  a way neither `after` nor `failing` catches would poison every job behind it. A job that needs
  a tenant must bind it itself and treat it as request-scoped — never assume a binding it did not
  make. **Octane is still unaddressed**, and adopting it still needs its own teardown.

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

## Preorder / backorder
- **Preorder is a permission to sell below zero, not a second counter.** `product_variants.allow_preorder`
  lifts the sufficiency check in `StockService::deductForSale()`, so `current_stock` goes negative.
  A balance of `-7` means "the shop has sold 7 units it doesn't have" — real, useful data, not
  corruption. `receivePurchase()` on the arriving shipment increments it straight back toward
  positive with no special case, and every movement in between still carries a coherent
  `balance_after`, so the ledger reconciles end to end. A separate `preorder_quantity` column
  would be a second source of truth for the same fact and would drift from the ledger.
- **Do NOT model preorder as `track_stock = false`.** That flag means "this has no meaningful
  count at all" (a haircut, a made-to-order cake). Using it for preorder makes the storefront
  report `in_stock` — so the customer only learns about the wait after paying, which is the
  entire problem — and `StoreRestockRequest` refuses to restock such a variant, so the shipment
  could never be counted in when it arrived.
- `preorder_lead_time_days` is a number of days, not a firm date: a shop importing stock
  genuinely doesn't know the exact day, and a date column invites a promise they'll break.
  Nullable, because "we don't know yet" is a real answer. Capped at 365 in validation — longer
  is far more likely a typo than a real promise.
- **`allow_preorder` is a permission, not a mode.** `StorefrontProductVariantResource::stockStatus()`
  only consults it once stock has run out, so a preorder-enabled variant that still has units
  reads as `in_stock` and ships today. Without that ordering, a shop that sensibly leaves the
  flag on permanently would advertise a wait on every item it actually had.
  `stock_status` therefore has four values: `in_stock`, `low_stock`, `out_of_stock`, `preorder`.
  `preorder_lead_time_days` is withheld from the storefront unless the status is `preorder`, so
  a client can't render "ships in 2 weeks" against something on the shelf.
- **`order_items.is_preorder` is per ITEM, not per order**, and is derived from the
  post-deduction balance rather than from the variant's flag — the flag says the shop is willing
  to sell below zero, the balance says this line actually did. Mixed carts are ordinary (a phone
  case in stock plus a phone on preorder), so a single order-level status couldn't express it.
  `Order::hasPreorderItems()` and `preorderReadyBy()` derive the order-level answer from the rows,
  same pattern as `refundRequired()`. `preorderReadyBy()` uses the LONGEST lead time (one parcel
  can only leave once everything lands) and counts from `created_at`, not from today — counting
  from now would push the estimate back a day every day and the promise could never come due.
  `is_preorder` and `preorder_lead_time_days` are snapshotted for the same reason as `unit_price`.
- `hasPreorderItems()` resolves cheapest-first — a `withCount` alias, then a loaded collection,
  then a query — so it's safe from a paginated list. `OrderService::listOrders()` supplies the
  alias (`preorder_item_count`) rather than eager-loading every order's whole cart.
- **`scopeLowStock()` excludes negative stock; `scopeOversold()` reports it.** A variant at `-7`
  isn't running low, it's oversold — a different problem needing a different action (chase the
  supplier, not reorder soon). Every variant lands in exactly one dashboard bucket instead of
  being nagged about twice. `scopeOversold()` deliberately does NOT filter on `allow_preorder`:
  units already sold are still owed even if the shop unticks the box afterwards.
- Cancelling a preorder calls the ordinary `returnStock()`, moving `-7` to `-4`. The backlog
  shrinks; stock the shop never had is never invented. `supplier_delay` is its own cancellation
  reason, kept distinct from `out_of_stock` — one means the shop ran out, the other means it
  knowingly sold ahead and the supplier slipped, and counting them together hides which of the
  two supply problems is actually costing orders.
- **`preorder_requires_prepayment` refuses deferred payment on a preorder line.** A three-week
  COD preorder is a promise with nothing behind it: the shop pays the importer up front and the
  customer can walk away at the door. Per VARIANT because the risk scales with the item — a shop
  sends a phone case COD and would never send an imported phone the same way, and both sit in one
  catalogue.
- The rule keys on `PaymentMethodCatalog::collectsUpfront()` — **when the money arrives, not who
  processes it.** `qr_transfer` has no gateway at all yet is paid in full up front, so it passes;
  a future "pay at pickup" would be deferred whatever handled it. Unknown methods are treated as
  deferred and refused: this gates goods the shop doesn't have yet, so failing closed is the
  right bias, and a forgotten flag on a new method surfaces immediately as a 422 rather than
  silently as an unpaid import.
- Enforced in `OrderService::createOrderItems()`, **after** the deduction and **inside** the
  transaction, throwing `PreorderRequiresPrepaymentException` (422). It has to be after, because
  only the post-deduction balance knows the line actually went below zero — a pre-check against
  `current_stock` would race with a concurrent order. One offending line refuses the whole order,
  which is correct rather than harsh: an order carries a single payment method, so there is no
  way to part-pay one line and defer another. A null `payment_method` is a POS sale, already
  recorded as paid at the counter, so the rule doesn't apply.
- `StorefrontProductVariantResource` publishes the flag (withheld unless the status is
  `preorder`, same as the lead time) so checkout can hide COD, but that is **advisory only** —
  the server check is the real one, since a client that ignores it must still be refused.
- **Still not built:** partial payments — take a deposit, collect the rest on delivery. That's
  the complete answer, and `payment_status` already has an unused `'partial'` waiting for it.
  What exists today is the all-or-nothing gate, which needs no new payment flow and can be
  replaced by deposits later with no migration. Don't build deposits without asking.

## Payments
- Payment *method* (what the customer picks: `card`, `cod`) and *gateway* (who processes it:
  `stripe`, null for manual) are separate columns on `tenant_payment_methods` on purpose. One
  gateway serves many methods, and method ids must never name a provider — a shop moving cards
  from Stripe to 2C2P changes one `gateway` value, and `stripe_card` would become a lie.
- Stripe uses **Connect with direct charges**: the charge is created on the shop's connected
  account (`tenants.stripe_account_id`), so the shop is merchant of record and bears its own
  fees, refunds and chargebacks. Destination charges were rejected — they'd make this platform
  liable for every shop's disputes. Only an `acct_...` id is stored per tenant; there are no
  per-tenant secrets anywhere.
- **The platform's own Stripe account is Thai, and that constrains the Connect model — this is
  not a style choice, and it will look wrong to anyone following a standard Stripe tutorial.**
  Accounts are created with explicit `controller` properties rather than the usual
  `type => 'express'` shorthand, because:
  - `type => 'express'` silently means the *platform* absorbs losses when a seller can't cover
    a chargeback, and Stripe forbids that for Thailand-based platforms ("platforms in TH cannot
    create accounts where the platform is loss-liable").
  - `stripe_dashboard[type] => 'express'` is separately rejected for the same reason: Stripe
    requires platform loss-liability for the Express dashboard.
  - Those two constraints only intersect at the Standard shape, so **a Thai platform cannot
    offer Express accounts at all.** Shop owners get the full Stripe dashboard — more surface
    than a small shop needs, but it's forced.
  The upside is the stronger position: `losses.payments => 'stripe'` means Stripe covers a
  seller's unpayable chargebacks, not this platform. Stating liability explicitly in
  `controller` rather than implying it through a `type` string is also the better shape for
  money-handling code. Do NOT "simplify" this back to `type => 'express'` — it fails at
  account creation. See `StripeConnectService::createAccount()`.
- **Manual methods (`gateway = null`) are the primary path, not an edge case.** This product's
  users are largely Myanmar nationals running shops in Thailand, who frequently cannot complete
  a card processor's KYC (Thai bank account, work permit, Thai-national assumptions in the
  forms). `cod` and `qr_transfer` need no gateway, no verification and no onboarding — a shop
  sells the day it signs up. Stripe is the opt-in upgrade for shops that CAN verify, never the
  default. Don't remove manual methods to "simplify"; they carry the volume.
- **A payment screenshot is a claim, not proof.** `payments.proof_path` holds the customer's
  transfer screenshot for `qr_transfer`, and nothing may treat its presence as payment — it's
  trivially forged and often just wrong (right amount, wrong shop). It exists so a human can
  glance and decide. `settleManualPayments()` is restricted to `gateway = 'manual'` so a shop
  ticking a box can never settle a card payment, which only its webhook may do.
- **Only a webhook may mark an order paid** (for gateway-backed methods). A browser redirect can be faked, lost or closed,
  so the storefront's success page displays state and never sets it. Idempotency comes from
  `payments.unique(['gateway','transaction_ref'])` plus a `lockForUpdate()` re-read — providers
  redeliver routinely, and that must be a no-op, not a second payment.
- **Cancelling and refunding are separate actions, deliberately.** For manual methods the
  money moved directly between customer and shop and never touched this platform, so a refund
  is something we can RECORD but never PERFORM — the shop has to open their banking app.
  Collapsing the two would have the system claim money was returned the moment cancel was
  clicked, possibly days before it actually was, or never. Cancelling therefore leaves
  `payment_status` alone; `Order::refundRequired()` derives "cancelled, money received, not
  yet sent back" so the obligation is visible rather than left to memory.
- **`POST /orders/{order}/cancel` and `/refund` are the only paths to those states** —
  `UpdateOrderRequest` deliberately refuses `status=cancelled` and `payment_status=refunded`.
  Both actions carry required inputs and an audit trail (reason, who, when) plus side effects
  (stock returning, payment rows reversing) that a generic field edit can't enforce and
  shouldn't trigger silently. Don't "simplify" by re-allowing them on the PATCH.
- Cancellation reasons come from `CancellationReasonCatalog`, not free text — a reason is only
  useful once it can be counted, and free text can never be grouped. `other` requires a note.
- Gateway calls happen **outside** the order transaction. `createOrderItems()` holds row locks
  on every variant it deducts; holding those across a network round-trip would let a slow
  gateway stall every checkout touching the same product. `CheckoutService` commits first, then
  initiates, and compensates by cancelling (which restores stock) if initiation fails.
- Stock is reserved at order creation and released by the provider's expiry webhook
  (`checkout.session.expired`), so no scheduler is needed. Expiry releases stock; a *failed*
  payment deliberately does not — a declined card isn't an abandoned order, and the customer
  may retry. Gateways without an expiry event will need a sweeper calling the same path.

## Platform billing (tenants paying US)
- **The opposite direction to everything in `Payments` above.** That section is Connect: money
  customer → shop, shop as merchant of record, platform deliberately not liable. This is money
  shop → platform, charged on the platform's OWN Thai account, platform as merchant of record.
  Same vendor, opposite direction, opposite liability — so they share the SDK and nothing else:
  separate config (`config/billing.php`), separate webhook endpoint, separate signing secret.
  Pointing both at one `STRIPE_WEBHOOK_SECRET` would let either endpoint accept the other's
  traffic — subscription events hunting for an order that doesn't exist, and vice versa.
- `tenants.stripe_account_id` (`acct_…`, money IN to the shop) and
  `subscriptions.external_customer_ref` (`cus_…`, money OUT to us) are the easiest two things in
  this codebase to confuse. Check which direction you're in before touching either.
- **What a shop SELLS in and what it PAYS US in are two different facts**, and conflating them
  was a real bug. `tenants.currency` interprets every order total — which is why it's immutable,
  since changing it would retroactively reinterpret history. Billing briefly borrowed that column
  AND borrowed its immutability as if it were a billing rule; the constraint exists for an
  unrelated reason. `subscriptions.billing_currency` is now the billing fact.
- `BillingCurrency::for()` resolves: the override, then the shop's selling currency, then
  `billing.default_currency`. **NULL is the normal state** and means "follow the selling
  currency" — right for almost every shop (a Yangon shop sells Kyat and banks in Kyat), so nobody
  has to think about it. A value is visibly a deliberate override. The third step is a fallback,
  not a decision: a USD-selling shop lands there, and those are the shops most likely to need an
  override.
- **Only platform staff may set it** (`POST /platform/subscriptions/{id}/billing-currency`).
  Left to the shop it would be an arbitrage lever rather than a preference — the ladders are not
  at parity (Pro is 699 THB against 89,000 MMK, roughly 636 THB) and the gap moves with FX.
  Changing it VOIDS pending transfer invoices rather than converting them: a pending invoice
  carries an amount and bank details the shop was told to use, and reinterpreting either would
  put a figure in front of a reviewer that nobody asked the shop to pay. Paid invoices are never
  touched — their currency is snapshotted and records money that actually moved.
- The original single platform-wide currency (THB, since the platform's Stripe account is Thai)
  was wrong for a different reason worth remembering: a shop inside Myanmar cannot easily wire
  Baht to a Thai bank (capital controls, not inconvenience), so a Baht-only bill broke the manual
  rail for exactly the shops it exists for.
- `GET /billing` returns `rails` (usable) AND `rail_status` (every rail with a REASON:
  `available` / `currency_unsupported` / `not_configured` / `disabled`). A bare list made two very
  different situations look identical to a shop — "we haven't set this up yet" and "this can never
  work in your currency" — so a Myanmar shop was invited to get in touch about a card option that
  cannot exist. `billing.currencies.*.stripe_supported` states the provider fact; the refusal
  message drops the word "yet" when the answer is permanent.
- Prices, Stripe price ids AND the platform's receiving bank account are all **per currency**
  (`config('billing.currencies')`). A Stripe Price carries exactly one currency, so price ids are
  per plan AND per currency AND per mode. **MMK has no price id and never will: Stripe does not
  support it**, so card is structurally unavailable to a Myanmar shop and transfer is its only
  rail. That is the concrete reason the manual rail had to be first-class rather than a fallback.
- The bank details in `config('billing.currencies.*.manual')` are the **platform's own receiving
  accounts** — where shops send subscription money. Not to be confused with
  `tenant_payment_methods.qr_path`/`instructions`, which are the SHOP's details facing its own
  customers. Three bank-ish things in this codebase, two of them belonging to the shop.
- **`subscriptions` is the single source of truth for plan and access.** The unused
  `plan`/`subscription_status`/`trial_ends_at`/`subscription_ends_at` columns were DROPPED from
  `tenants` rather than kept in sync — two places holding one fact means a shop's abilities
  depend on which one the enforcement code happens to read. Unique index on `tenant_id`: "which
  plan is this shop on" must have exactly one answer.
- **Plan and access are orthogonal axes, and this is the design most likely to be "simplified"
  later.** A lapsed Pro shop stays on Pro and becomes READ-ONLY; it does not silently become a
  Starter shop. Quietly shrinking what someone bought, while still billing them as Pro, is a
  half-state nobody can explain to a customer. `Subscription::effectivePlan()` falls back only
  when the stored plan has left the catalogue, never as a consequence of non-payment.
- `PlanCatalog` (limits + features) is a code constant for the same reason
  `PaymentMethodCatalog` is: each entry implies an enforcement point the app must implement,
  which a row someone inserts can't supply. Prices and Stripe price ids live in
  `config/billing.php` instead — they're deployment facts, and **Stripe issues different price
  ids in test and live mode**, so a hardcoded `price_…` works in dev and charges nothing in prod.
- **Two rails, both first-class: Stripe card and manual bank transfer.** A shop owner in Yangon
  with a Kyat account cannot pay Stripe at all, so a card-only design would exclude a large share
  of the market. Same reasoning that makes `cod`/`qr_transfer` primary rather than edge cases.
  The manual rail gets a longer grace window (`manual_grace_days`) because its failure modes are
  slower: a transfer has to be sent, clear, and then be checked by a human here.
- `subscription_invoices.proof_path` is a CLAIM, not proof — identical rule to `payments.proof_path`,
  and it matters more here because the party uploading the screenshot is the party being billed.
  Only `approved_at` settles an invoice. `approved_by` is deliberately NOT an FK to `users`:
  every user belongs to a tenant, so that FK would model "a shop approved its own payment".
- Grace is DERIVED from `current_period_ends_at`, never stored, so it can't disagree with the
  period it follows. A deliberate cancellation gets NO grace — grace absorbs payment friction,
  and someone who chose to leave has none to absorb.
- **A null end date reads as expired, never as eternal.** The bug this replaced: `register()` set
  no `trial_ends_at`, so every shop ever created was on an unlimited free trial.
- **Enforcement bites on CREATE, never on READ.** A shop over its limit keeps every product,
  order and report row it has; it just can't add the next one. Being over the limit is a coherent
  state, the same position taken on a variant sitting at -7 stock. Nothing is ever deleted or
  hidden to force an upgrade.
- The `subscription` middleware (read-only lockout) is applied to catalogue and config WRITES
  only. What it's kept OFF is the actual design: the public storefront (customers hold those
  links and did nothing wrong), orders — POS, online, cancel, refund and dispatch alike (blocking
  fulfilment strands a parcel a customer already paid for; blocking sales stops the shop earning,
  and a shop that can't trade can't pay), and billing itself (locking the renew button behind an
  active subscription is the one unrecoverable bug in this feature).
- Route-level feature gates use `plan:<feature>` middleware. A feature that's one FIELD on a
  larger request (`allow_preorder`) is gated in the SERVICE instead, so the rest of the request
  still succeeds rather than a whole product edit failing with a billing error. Turning a paid
  feature OFF is never gated, or a downgraded shop is stuck with it.
- **Dispatch tracking was considered as a Pro feature and rejected.** It looks like a classic
  logistics upsell, but COD plus delivery is how most shops in this market actually trade —
  gating it would gate the product. Three real gates beat four where one is wrong.
- 402 throughout, not 403. The shop IS allowed; it hasn't paid. 402 lets the admin app show an
  upgrade prompt without sniffing message text to tell billing apart from a permission failure.
- `PlanGate` returns permissive when NO tenant is bound — webhooks, console commands and jobs are
  the platform acting on its own data, not a shop spending its allowance. `TenantScope::apply()`
  takes the same position, and disagreeing would give two answers to "whose request is this". A
  tenant WITH no subscription row fails closed instead: that state can't come from registration,
  so reaching it means data was made around the app, and treating it as unlimited would make
  "delete your subscription row" the cheapest upgrade available.
- Laravel Cashier was rejected: Stripe-only, so it can't model the manual rail at all, and it
  ships duplicate tables and webhook handling that collide with the existing `PaymentGateway`
  abstraction.
## Platform admin
- **Platform staff are `platform_admins` rows, NEVER `users` rows**, and that separation is the
  security model rather than tidiness. `TenantScope::apply()` only adds its filter when
  `currentTenantId()` is truthy, and that falls back to `auth()->user()?->tenant_id` — so a
  super-admin modelled as a `User` with `tenant_id = null` resolves to null, the scope adds NO
  WHERE CLAUSE AT ALL, and that account silently reads every tenant's products, orders and
  customers through the existing endpoints. Not by design; by accident of a null check. With a
  separate model there is no `tenant_id` to be null and no row in `users` to authenticate as.
  **Never add an `is_super_admin` flag to `users` to "simplify" this.**
- **A separate guard in `config/auth.php` would NOT have been enough, and adding one would give
  false confidence.** Sanctum's personal access tokens are polymorphic and its guard
  authenticates whatever model a token points at without consulting the configured provider — so
  `auth:sanctum` alone lets either identity through either door. Isolation is therefore enforced
  by explicit TYPE checks at both doors: `EnsurePlatformAdmin` (`platform` middleware) asserts a
  `PlatformAdmin`, and `ResolveTenant` asserts a `User` before touching `->tenant`. Both are
  covered by `tests/Feature/Platform/PlatformAccessTest.php`; don't weaken either.
- `is_active` is re-checked on every request, not only at login, so revoking staff takes effect
  immediately rather than whenever their token expires.
- **There is deliberately no sign-up endpoint for platform staff** — accounts are minted by
  `php artisan platform:create-admin`, so issuing one needs shell access rather than merely
  reaching the API.
- `SubscriptionReviewService` is the manual rail's equivalent of a payment webhook: the ONLY path
  by which a bank transfer becomes a paid plan, and written to the same standard — `lockForUpdate()`
  then re-check status (approving twice must be a no-op, not a second month), and a hard refusal
  to settle a `gateway = 'stripe'` invoice, which only its webhook may settle.
- Every query there crosses tenants and strips `TenantScope` **explicitly**, even though it would
  no-op anyway for an admin with no tenant bound — relying on that would make cross-tenant access
  an accident of who is logged in. Same reasoning as `StorefrontProductService::findPublicVariant()`.
  Route params are ids, not model bindings, for exactly the same reason.
- Rejecting leaves the invoice UNPAID rather than voiding it, so the shop can transfer again and
  upload against the same one (`ManualBillingRail` reuses an unpaid invoice). A reason is required:
  a shop told only "rejected" opens a support ticket asking why.
- `subscription_invoices.reviewed_by`/`reviewed_at` are named for REVIEW, not approval, because a
  human can rule either way — `approved_by` holding the id of someone who rejected a forged
  screenshot would be a column whose name lies. `status` carries the outcome, `paid_at` the money.
  The FK points at `platform_admins` and must never point at `users`.
- `PlatformInvoiceResource` names the SHOP and is a separate class from `SubscriptionInvoiceResource`
  rather than a flag on it — two resources cannot leak into each other; one with a conditional can.
  `PlatformShopResource` is separate from `TenantResource` for the same reason: it exposes owner
  contact details and billing internals.
- **`tenants.suspended_at` is NOT `is_active`, and the difference is the point.** `is_active`
  makes `ResolveTenant` 404 on BOTH branches, taking the public storefront down and stranding
  customers mid-order — the right hammer for fraud, far too heavy for "we need to talk to this
  shop". Suspension is checked in `ResolveTenant`'s AUTHENTICATED branch only, so the owner is
  locked out while the storefront keeps serving. Moving that check above the branch, or reusing
  `is_active`, silently turns a support action into taking a shop's business offline.
  `ShopSuspendedException` is 403 with `reason: 'shop_suspended'`, not 402 — paying doesn't fix
  it, and the admin app renders 402 as an upgrade prompt. A reason is required, same rule as a
  rejected transfer. The hard `is_active` switch is deliberately NOT exposed in the dashboard.
- `PlatformShopService`'s scope discipline differs from every other cross-tenant service and is
  easy to get wrong: `Tenant` carries no `TenantScope` (it IS the tenant), but its relations do —
  so every eager load, `withCount` and `whereHas` strips the scope explicitly. Its `unscoped()`
  helper accepts `Builder|Relation` because eager-load closures are handed the relation.
- Directory filtering on `plan` matches the plan OF RECORD, not `effectivePlan()` — a scheduled
  downgrade isn't expressible in SQL, since it's derived from two dates.
- **Staff accounts can now be created through the API, which deliberately lowers the bar** from
  "needs shell access" to "needs a session". `platform:create-admin` stays: it is how the first
  account exists and the way back from a total lockout. An admin **cannot deactivate themselves**
  — one click would otherwise lock every human out of the payment queue. Deactivation never
  deletes: `EnsurePlatformAdmin` re-checks `is_active` per request so revocation is immediate,
  and `subscription_invoices.reviewed_by` points here.
- `GET /platform/billing/invoices` (ledger, all statuses, newest first) is separate from
  `/billing/pending` (the review QUEUE, actionable first). Different questions; both live in
  `SubscriptionReviewService` because splitting would duplicate the bypass discipline.
- Approving or rejecting sends `SubscriptionPaymentReviewed` to the shop's users. The reason
  travels with a rejection, since a shop told only "rejected" cannot act on it.

## Billing notifications
- Four events reach the shop: payment received (both rails), transfer rejected, card payment
  failed, and cancellation. All extend `BillingNotification`, which states the queue decisions
  once instead of four drifting copies.
- **Mail is queued; the in-app notification is NOT.** `viaConnections()` puts the `database`
  channel on the `sync` connection, so the bell keeps working when no worker is running — the
  normal state of this project — and forgetting to start one delays email rather than silently
  breaking notifications. This is the same hazard `NewOnlineOrderReceived` avoids by not queueing
  at all; here only the slow channel is deferred.
- `$afterCommit` is set in `BillingNotification::__construct()` via `Queueable::afterCommit()`,
  NOT redeclared as a property — `Queueable` already declares it, and PHP rejects a trait
  property redeclared with a different default. That fatal fires during bootstrap, where it
  surfaces as a silent crash with no output rather than an error. Subclasses must call
  `parent::__construct()`.
- It matters beyond tidiness: these are sent from inside the transaction that settles a payment,
  so without it a worker could email "payment confirmed" for a ruling that then rolled back.
- The card rail used to notify NOBODY — not even in-app. `BillingWebhookProcessor` now does.
- Stripe deleting a subscription the shop already cancelled through this app does not notify a
  second time; only a cancellation we didn't already know about speaks up.
- `MAIL_MAILER=log` in development, so mail lands in `storage/logs/laravel.log`. A real transport
  is needed before any of this reaches a shop.
- **A rail, not a gateway.** `BillingRail` is deliberately NOT `PaymentGateway`: that contract is
  shaped around an Order and a connected account, and sharing it would mean one side always
  passing arguments meaningless to it. "Rail" because the manual one has no gateway at all, and
  calling it a gateway would make the primary path here read as the exception.
- `StripeBillingRail` passes **no `stripe_account` option anywhere** — that absence is the most
  important thing in the class. `StripeGateway` passes it on every call so a customer's money
  lands on the shop's account; here the platform is the merchant, so adding it would bill the
  shop on its own account and pay the money to itself.
- `subscription_data.metadata` on the Checkout Session (not just session metadata) is what makes
  the webhook work: `invoice.paid` and `customer.subscription.*` carry the subscription and know
  nothing about the session that created it.
- **`POST /billing/subscribe` never changes the plan, the status or `gateway`.** A redirect can be
  closed and a bank transfer may never be sent. Same rule as orders: only confirmed money moves
  state — a webhook on the card rail, a human on the manual one.
- Asking to pay by transfer twice REUSES the unpaid invoice rather than raising a second one.
  Asking for a DIFFERENT plan voids the earlier pending one — otherwise a shop that changed its
  mind owes two invoices with one screenshot between them, and approving both grants two periods.
- **The three plan-change cases diverge deliberately**, keyed on `PlanCatalog::rank()` (declaration
  order in `PLANS` is the ladder, not price — prices are per currency and could cross over):
  - **Renew** (same plan): period starts at `current_period_ends_at`, so paying early extends
    rather than discarding the remainder.
  - **Upgrade**: period starts NOW, because that is when the shop actually gets the higher plan —
    an invoice claiming a period that began after access did can't be reconciled against a bank
    statement. The unused cheap-plan days are forfeited; proration would need credit notes this
    app has no model for.
  - **Downgrade** with paid time left: period starts at the period end, and the change is
    SCHEDULED (`pending_plan` + `pending_plan_starts_at`), never applied on approval. The shop
    paid for the higher plan through this period and keeps it — taking a paid feature back
    mid-period is the one thing this design refuses to do. `Subscription::effectivePlan()` flips
    on the date, so **no scheduler exists or should be added**; a nightly job would be a second
    source of truth for what the dates already answer. A downgrade with no paid time left applies
    immediately, since there is nothing to protect.
- `effectivePlan()` is the ONLY thing anything may read for entitlement. `plan` is the plan as of
  the current period; `pending_plan` is an agreed future change.
- A shop with a live Stripe subscription is refused **both** rails (`BillingActionUnavailableException`,
  422), not just a second Checkout. A second Checkout would have Stripe charge twice a month; a
  bank transfer is worse in a quieter way, because approving it flips `gateway` to `manual` while
  Stripe carries on charging the card and nothing in the app shows the double billing. Changing
  plan on an existing Stripe subscription needs the update API plus proration and is deliberately
  unbuilt; cancel-then-resubscribe costs the shop nothing, since cancelling keeps the paid period.
- **`POST /webhooks/billing/{rail}` is a SEPARATE endpoint from `/webhooks/{gateway}`**, with its
  own signing secret (`STRIPE_BILLING_WEBHOOK_SECRET`). Merging them would let either accept the
  other's traffic. `BillingWebhookProcessor` mirrors `WebhookProcessor`: lock, then re-check, so a
  redelivery is a no-op rather than a second month, with `subscription_invoices.unique(gateway,
  external_ref)` as the backstop.
- The webhook records the amount **Stripe reports**, unlike the order webhook which validates
  against `$order->total`. There we set the amount; here Stripe does, and proration makes our
  config the wrong reference rather than a safety check.
- The FIRST `invoice.paid` is what stores `external_subscription_ref` — `POST /billing/subscribe`
  deliberately stores nothing, so `BillingWebhookProcessor` resolves by subscription ref, then
  customer ref, then the `tenant_id` in `subscription_data.metadata`.
- A failed charge goes `past_due` and does NOT cut access; the existing grace window handles it.
  Same position as the order webhook, where a failed payment deliberately does not release stock.
- `App\Services\Stripe\StripeMoney` holds the zero-decimal currency list, shared by both
  directions of money. Deliberately one copy — a wrong entry is a silent 100x error, and two
  copies would eventually disagree with only one of them wrong.
- **Not built yet:** plan changes on a live Stripe subscription (needs the update API plus
  proration; cancel-then-resubscribe is the interim answer and costs the shop nothing), platform
  METRICS (recurring revenue must be reported PER CURRENCY — summing THB and MMK needs an FX rate
  this app has no model for, and a wrong one silently misstates revenue), roles within platform
  staff, and enforcement of the `staff` limit (this app has no shop-side staff-management
  endpoint, so nothing can create a second user yet).

## Delivery / logistics
- `delivery_providers` is a **per-tenant** table, not a platform catalogue like
  `PaymentMethodCatalog` or `CancellationReasonCatalog`. Those are fixed because each entry
  teaches the app a behaviour it has to implement; a courier teaches it nothing. The real set is
  open and regional (Royal Express/Ninja Van/J&T in Myanmar, Kerry/Flash/Thailand Post in
  Thailand, plus "our own rider"), so a hardcoded list would be wrong within a week and wrong
  differently per country. Still a table rather than free text on the order, for the same reason
  cancellation reasons aren't free text: "which courier is losing our parcels?" needs grouping,
  and "Royal", "royal express" and "RE" are three couriers to a `GROUP BY`.
- **Dispatch is NOT an order status.** `orders.status` tracks the commercial lifecycle
  (`pending → paid → completed`); dispatch tracks a physical parcel, and they're different axes —
  a COD order is dispatched while still unpaid, a pickup order completes without ever being
  dispatched. `Order::isDispatched()` derives "it's on its way" from `dispatched_at`, so the fact
  isn't stored twice. `dispatchOrder()` deliberately leaves `status` and `payment_status`
  untouched; nudging status there would assert something about the money that isn't true.
- `orders.delivery_provider_name` is snapshotted next to the FK, same pattern as
  `order_items.product_name`. The FK is `nullOnDelete` so a shop can drop a courier it's finished
  with, and every order it already carried still says who carried it. The FK answers "group my
  orders by courier", the snapshot answers "who took THIS one" — and keeps answering after the
  row is gone. That snapshot is the only reason `DeliveryProviderService::delete()` can be a hard
  delete rather than a soft one.
- `POST /orders/{order}/dispatch` is its own action for the same reason cancel and refund are:
  required input plus an audit trail (`dispatched_by`, `dispatched_at`) a generic PATCH can't
  enforce. Calling it again re-dispatches and overwrites `dispatched_at` — when a courier loses a
  parcel and the shop sends a replacement, the useful answer to "when did this go out" is the
  time it actually went out. Refused for `fulfillment_type = 'pickup'` and for cancelled/refunded
  orders.
- `DispatchOrderRequest` resolves the courier through the tenant-scoped model
  (`DeliveryProvider::whereKey($value)->exists()`), never `exists:delivery_providers,id` — the
  plain rule would match across tenants. `dispatchOrder()` re-resolves it again through the same
  scope rather than trusting the validated id, the same defence-in-depth as `resolveCartLines()`.
- `DeliveryProviderResource` is admin-only. The courier's `phone` is the shop's operational
  contact for chasing a parcel, not something to publish to customers — a customer gets the
  courier NAME and `tracking_number`, which is enough for the courier's own tracking page.
- **The delivery fee lives on the tenant and is NEVER taken from request input.** A money amount
  a client can send is a money amount a client can set to zero — the same rule that keeps
  `tenant_id` and `unit_price` out of the request body. `DeliveryFeeCalculator::for()` resolves
  it server-side at checkout; `orders.delivery_fee` snapshots it, so raising the shop's fee never
  rewrites what a past customer was charged.
- Pickup is free **structurally**, not by configuration: `DeliveryFeeCalculator` returns 0 for
  any `fulfillment_type` that isn't `delivery`, so a shop offering both can't accidentally bill
  someone collecting in person. A POS counter sale has no `fulfillment_type` at all and lands in
  the same branch.
- `DeliveryFeeCalculator` is its own class purely as a seam. Today it's a flat per-shop fee;
  zone/township pricing, free-over-a-threshold and per-courier rates are all ordinary asks here,
  and each is a change to that one method with `OrderService` untouched. Zones specifically need
  an address-to-zone mapping this app has no data for — `delivery_address` is deliberately free
  text (see `StorePublicOrderRequest`), so there's nothing structured to match against yet.
- `OrderService::calculateTotal()` is the one definition of what an order costs, written out in
  full (`subtotal - discount + tax + delivery_fee`) even though discount and tax are hardcoded 0
  at both call sites. When either becomes real the arithmetic is already right and in one place,
  instead of being rediscovered separately in the POS and storefront paths. Everything
  downstream follows automatically — `CheckoutService` and `StripeGateway` both charge
  `$order->total`, so the fee is charged without either knowing it exists.
- **`Order::GOODS_REVENUE_SQL` (`total - delivery_fee`) excludes the fee from margin reporting.**
  The fee is largely money the shop collects and hands to a courier, and nothing records what the
  courier was actually paid — so counting it as revenue while only goods appear in cost inflates
  both revenue and profit by the full fee. A shop charging 2,000 to ship and paying 2,000 has
  made nothing on delivery. It's a shared constant for the same reason `REVENUE_STATUSES` is one:
  `DashboardService`'s today card and `ReportService`'s range report both use it, so they can
  never disagree about a day's sales. The fee is still reported — `delivery_fees_collected` and
  `today_delivery_fees` — so the numbers still reconcile against the till. Excluded from margin,
  never hidden.
- `refunds_owed_total` deliberately still sums raw `total`: a cancelled order owes the customer
  back everything they paid, delivery included.
- Not built: a per-courier tracking-URL template (a link the admin can click), deferred because a
  URL containing a `{tracking_number}` placeholder doesn't survive `url:https` validation
  cleanly. Also not built: recording what the shop PAID the courier — that's what would let
  delivery show a real margin instead of being excluded from one.

## Multi-country
- This SaaS serves Myanmar AND Thailand, so nothing may assume one country.
- `tenants.currency` is chosen at **signup** and is effectively **permanent** —
  `UpdateTenantRequest` deliberately refuses it. Money columns carry no currency tag, so
  changing it once orders exist would retroactively reinterpret every historical total.
  Validated against `SupportedCurrency`, a deliberately short list: each entry needs its
  minor-unit handling checked (see `StripeGateway::isZeroDecimal` — a wrong entry is a silent
  100x charge error), so widening it is a deliberate act, not a config tweak.
- `tenants.timezone` IS editable, unlike currency — changing it only reinterprets wall-clock
  opening hours going forward and rewrites no history. It exists because `business_hours` are
  wall-clock times with no zone attached, and Yangon (UTC+6:30) vs Bangkok (UTC+7) is a real
  30-minute difference, not a rounding one.

## Architecture pattern
- Controllers (`app/Http/Controllers/Api/`) are thin: validate via a Form Request, call a
  Service, return a Resource. No business logic in controllers, ever.
- Business logic lives in `app/Services/`.
- API Resources (`app/Http/Resources/`) control exactly what each endpoint exposes — critical for
  keeping `buying_price`/`unit_cost` out of any storefront-facing response, since only admin
  should see cost data.

## Testing
- Pest, one test file per feature. Run `php artisan test` before considering any task done.
- `.env.testing` exists and points at sqlite `:memory:`. It is not optional hygiene: without it
  `php artisan <cmd> --env=testing` falls back to `.env` and hits the DEV MySQL database — that is
  how `migrate:fresh --env=testing` once dropped every table in development. The sqlite override
  in `phpunit.xml` applies only to Pest/PHPUnit runs, never to an artisan command.
- It also pins `BILLING_*` values, so the suite asserts behaviour rather than whatever the
  platform currently charges. Before it existed, repricing a plan in `.env` failed two tests.
- Sanctum caches the resolved user for the whole test process, and that cached User carries
  already-loaded relations. A test that changes model state and then makes a SECOND authenticated
  request will answer from the stale relation. Split it into two tests, or assert against the
  models — see the note on `createPosOrderForTenant` in `tests/Pest.php`.

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
