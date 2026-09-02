<?php

namespace App\Services\Platform;

use App\Exceptions\BillingActionUnavailableException;
use App\Models\PlatformAdmin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

/**
 * Platform staff accounts.
 *
 * Worth being clear-eyed about what this changes: minting an account that can
 * read and settle money across every shop used to require SHELL access
 * (`php artisan platform:create-admin`), and now requires a session. That is a
 * real reduction in the bar, accepted deliberately for operational
 * convenience. The command stays — it is how the first admin exists at all,
 * and how you recover if every account here gets deactivated.
 *
 * Deactivation rather than deletion, always: EnsurePlatformAdmin re-checks
 * is_active on every request so revocation is immediate, and
 * subscription_invoices.reviewed_by points here — deleting a row would erase
 * who confirmed a payment.
 */
class PlatformAdminService
{
    public function list(int $perPage = 25): LengthAwarePaginator
    {
        return PlatformAdmin::query()->orderBy('name')->paginate($perPage);
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function create(array $data): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);
    }

    /**
     * $actor is the admin making the request, and the reason this method takes
     * it at all: an admin deactivating THEMSELVES would, if they were the last
     * active account, lock every human out of the payment queue with no way
     * back except the artisan command. Refusing self-deactivation makes that
     * mistake unreachable rather than merely unlikely.
     */
    public function deactivate(int $adminId, PlatformAdmin $actor): PlatformAdmin
    {
        if ($adminId === $actor->id) {
            throw new BillingActionUnavailableException(
                'You cannot deactivate your own account. Ask another admin to do it.'
            );
        }

        return $this->setActive($adminId, false);
    }

    public function reactivate(int $adminId): PlatformAdmin
    {
        return $this->setActive($adminId, true);
    }

    private function setActive(int $adminId, bool $isActive): PlatformAdmin
    {
        $admin = PlatformAdmin::find($adminId);

        abort_if($admin === null, 404, 'Platform admin not found.');

        $admin->forceFill(['is_active' => $isActive])->save();

        // Tokens are deliberately NOT deleted on deactivation. They stop
        // working immediately anyway — EnsurePlatformAdmin checks is_active on
        // every request — and keeping them means reactivating restores the
        // account rather than silently requiring a fresh sign-in.
        return $admin;
    }
}
