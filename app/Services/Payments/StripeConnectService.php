<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Onboards a shop onto Stripe Connect so it can receive its own money.
 *
 * The shop never types bank details, tax ids or identity documents into
 * this application — Stripe hosts that entire flow. That's most of the
 * point of Connect over asking each shop for API keys: sensitive
 * onboarding data never touches these servers, and we're not responsible
 * for verifying anybody's identity.
 */
class StripeConnectService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Returns a one-time Stripe-hosted onboarding URL for this shop,
     * creating the connected account first if it doesn't have one.
     *
     * The account id is persisted BEFORE the link is generated. If the
     * order were reversed and persisting failed, the shop would be left
     * with an orphaned Stripe account and would silently create another
     * on the next attempt — accumulating duplicate accounts that are
     * awkward to clean up because they may already hold money.
     *
     * Account links are deliberately short-lived and single-use, so this
     * is safe to call repeatedly: an owner who abandons onboarding halfway
     * just requests a fresh link and resumes where they left off.
     */
    public function createOnboardingLink(Tenant $tenant, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $tenant->stripe_account_id ?? $this->createAccount($tenant);

        return $this->stripe->accountLinks->create([
            'account' => $accountId,
            'type' => 'account_onboarding',
            // Stripe sends the owner here when the link expires before
            // they finish. It must generate a NEW link rather than show an
            // error — a link timing out is normal, not a failure.
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ])->url;
    }

    /**
     * Configured with explicit `controller` properties rather than the
     * shorthand `type => 'express'`, which Stripe now recommends generally
     * — but here it's required, not stylistic.
     *
     * `type => 'express'` silently implies the PLATFORM absorbs losses when
     * a seller can't cover a chargeback. Stripe forbids that for platforms
     * based in Thailand, so account creation is rejected outright with
     * "platforms in TH cannot create accounts where the platform is
     * loss-liable". Since the platform account here is Thai (to serve the
     * Thailand market), liability has to be stated explicitly instead.
     *
     * Each key is a deliberate choice:
     *  - losses.payments = 'stripe' — Stripe bears the loss if a seller
     *    can't repay a chargeback. This is the arrangement the Connect
     *    setup summary described, and the reason a small platform isn't
     *    exposed to every shop's disputes.
     *  - fees.payer = 'account' — the shop pays its own Stripe fees, which
     *    is what "the shop is merchant of record" means in practice and
     *    what pairs with direct charges.
     *  - stripe_dashboard.type = 'full' — NOT 'express', and not by
     *    preference. Stripe rejects express here outright: "when
     *    stripe_dashboard[type]=express, your platform must collect fees
     *    and be liable for negative balances", which Thailand forbids. The
     *    two constraints only intersect at the Standard shape, so a Thai
     *    platform cannot offer the lightweight Express dashboard at all.
     *    Shop owners get the full Stripe dashboard instead — more surface
     *    than a small shop needs, but it's forced, not chosen.
     *  - requirement_collection = 'stripe' — Stripe hosts onboarding and
     *    owns verification, so identity documents never touch this app.
     *
     * The requested capabilities are what direct charges need: card
     * payments to accept money, transfers so it can be paid out.
     *
     * Country is deliberately not set — it's inherited from the platform
     * account. Letting a form choose it would produce accounts that fail
     * verification for reasons the shop owner can't act on.
     */
    private function createAccount(Tenant $tenant): string
    {
        $account = $this->stripe->accounts->create([
            'controller' => [
                'losses' => ['payments' => 'stripe'],
                'fees' => ['payer' => 'account'],
                'stripe_dashboard' => ['type' => 'full'],
                'requirement_collection' => 'stripe',
            ],
            'email' => $tenant->business_email ?? $tenant->owner_email,
            'business_profile' => [
                'name' => $tenant->name,
            ],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_slug' => $tenant->slug,
            ],
        ]);

        // Written through a fresh query rather than $tenant->save(): the
        // caller's instance may be stale, and this must not clobber any
        // other column it happens to be holding.
        DB::transaction(function () use ($tenant, $account) {
            Tenant::whereKey($tenant->id)->update(['stripe_account_id' => $account->id]);
        });

        $tenant->stripe_account_id = $account->id;

        return $account->id;
    }

    /**
     * Whether this shop can actually take money yet.
     *
     * charges_enabled is asked of Stripe rather than cached locally.
     * Verification state changes on Stripe's side without warning — a
     * document expires, a review completes, a payout method fails — and a
     * cached "yes" would mean offering card checkout to customers on a
     * shop that can no longer accept it.
     *
     * details_submitted distinguishes "hasn't finished the form" from
     * "finished but Stripe is still reviewing", which are different
     * messages for the shop owner.
     */
    public function status(Tenant $tenant): array
    {
        if (blank($tenant->stripe_account_id)) {
            return [
                'connected' => false,
                'details_submitted' => false,
                'charges_enabled' => false,
                'payouts_enabled' => false,
            ];
        }

        $account = $this->stripe->accounts->retrieve($tenant->stripe_account_id);

        return [
            'connected' => true,
            'details_submitted' => (bool) $account->details_submitted,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
        ];
    }
}
