<?php

namespace App\Services\Platform;

use App\Models\Concerns\TenantScope;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

/**
 * The shop directory behind the platform dashboard.
 *
 * The scope discipline here differs from every other cross-tenant service and
 * is easy to get wrong: `Tenant` does NOT use BelongsToTenant — it IS the
 * tenant — so Tenant::query() is already unscoped. Its RELATIONS do use it, so
 * every eager load, count and whereHas below strips TenantScope explicitly.
 *
 * With a platform admin authenticated there is no tenant bound and the scope
 * would no-op anyway. Saying it out loud keeps cross-tenant access a decision
 * rather than an accident of who happens to be logged in — the same position
 * SubscriptionReviewService and StorefrontProductService take.
 */
class PlatformShopService
{
    /**
     * Always stripped explicitly; never the blanket withoutGlobalScopes().
     *
     * Accepts a Relation as well as a Builder because eager-load and
     * withCount closures are handed the relation, not its query.
     */
    private function unscoped(Builder|Relation $query): Builder|Relation
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function directory(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with(['subscription' => fn ($q) => $this->unscoped($q)])
            ->latest('id');

        if ($search = Arr::get($filters, 'search')) {
            // Name, slug and owner email, because those are the three things a
            // support request actually arrives with.
            $query->where(function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('owner_email', 'like', $like);
            });
        }

        if ($currency = Arr::get($filters, 'currency')) {
            $query->where('currency', strtoupper($currency));
        }

        if (($suspended = Arr::get($filters, 'suspended')) !== null) {
            $query->{$suspended ? 'whereNotNull' : 'whereNull'}('suspended_at');
        }

        // plan / status / rail live on the subscription.
        $subscriptionFilters = array_filter([
            'plan' => Arr::get($filters, 'plan'),
            'status' => Arr::get($filters, 'status'),
            'gateway' => Arr::get($filters, 'rail'),
        ]);

        if ($subscriptionFilters !== []) {
            $query->whereHas('subscription', function (Builder $q) use ($subscriptionFilters) {
                // NOTE: `plan` is the plan OF RECORD, not effectivePlan() — a
                // scheduled downgrade isn't visible to SQL, since it's derived
                // from two dates. A shop mid-downgrade filters under the plan
                // it is on today, which is the useful answer for support.
                $this->unscoped($q)->where($subscriptionFilters);
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * One shop in full. Counts included because they're the fastest way to
     * tell a real business from an abandoned signup, which is the first thing
     * you want to know when a shop appears in the support queue.
     */
    public function detail(int $tenantId): Tenant
    {
        $tenant = Tenant::query()
            ->with([
                'subscription' => fn ($q) => $this->unscoped($q),
                'subscriptionInvoices' => fn ($q) => $this->unscoped($q)->latest('id')->limit(20),
            ])
            ->withCount([
                'products' => fn ($q) => $this->unscoped($q),
                'orders' => fn ($q) => $this->unscoped($q),
            ])
            ->find($tenantId);

        abort_if($tenant === null, 404, 'Shop not found.');

        return $tenant;
    }

    /**
     * Locks the owner out of their admin. Does NOT touch the storefront — see
     * the migration and ResolveTenant for why those are different actions.
     *
     * Re-suspending an already-suspended shop updates the reason rather than
     * refusing: correcting or expanding the note is a normal thing to want,
     * and the original timestamp is kept so "how long has this been going on"
     * still has an answer.
     */
    public function suspend(int $tenantId, string $reason): Tenant
    {
        $tenant = $this->find($tenantId);

        $tenant->forceFill([
            'suspended_at' => $tenant->suspended_at ?? now(),
            'suspension_reason' => $reason,
        ])->save();

        return $tenant;
    }

    public function restore(int $tenantId): Tenant
    {
        $tenant = $this->find($tenantId);

        // The reason is cleared with the suspension: leaving it behind would
        // have the shop's record permanently asserting something that is no
        // longer true.
        $tenant->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        return $tenant;
    }

    private function find(int $tenantId): Tenant
    {
        $tenant = Tenant::find($tenantId);

        abort_if($tenant === null, 404, 'Shop not found.');

        return $tenant;
    }
}
