<?php

namespace App\Services\Tenants;

use App\Exceptions\StaffActionUnavailableException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PlanGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * User carries no BelongsToTenant (login must resolve a user before any tenant
 * is bound), so every query here goes through $tenant->users() explicitly.
 */
class StaffService
{
    public function __construct(private readonly PlanGate $gate) {}

    public function list(Tenant $tenant): Collection
    {
        return $tenant->users()->orderBy('name')->get();
    }

    public function create(Tenant $tenant, array $data): User
    {
        return DB::transaction(function () use ($tenant, $data) {
            $current = $tenant->users()->lockForUpdate()->count();

            $this->gate->ensureWithin('staff', $current);

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
            ]);
        });
    }

    public function update(Tenant $tenant, int $userId, array $data, User $actor): User
    {
        return DB::transaction(function () use ($tenant, $userId, $data, $actor) {
            $user = $this->find($tenant, $userId);

            if ($user->id === $actor->id && array_key_exists('role', $data) && $data['role'] !== $user->role) {
                throw new StaffActionUnavailableException('You cannot change your own role.');
            }

            if (array_key_exists('role', $data) && $data['role'] !== ShopRole::Owner->value) {
                $this->guardLastOwner($tenant, $user);
            }

            $attributes = array_filter([
                'name' => $data['name'] ?? null,
                'role' => $data['role'] ?? null,
            ], fn ($value) => $value !== null);

            if (! empty($data['password'])) {
                $attributes['password'] = Hash::make($data['password']);
            }

            $user->update($attributes);

            return $user->fresh();
        });
    }

    public function delete(Tenant $tenant, int $userId, User $actor): void
    {
        DB::transaction(function () use ($tenant, $userId, $actor) {
            $user = $this->find($tenant, $userId);

            if ($user->id === $actor->id) {
                throw new StaffActionUnavailableException('You cannot remove your own account.');
            }

            $this->guardLastOwner($tenant, $user);

            $user->tokens()->delete();
            $user->delete();
        });
    }

    private function find(Tenant $tenant, int $userId): User
    {
        $user = $tenant->users()->whereKey($userId)->first();

        abort_if($user === null, 404, 'Staff member not found.');

        return $user;
    }

    private function guardLastOwner(Tenant $tenant, User $user): void
    {
        if ($user->role !== ShopRole::Owner->value) {
            return;
        }

        $remaining = $tenant->users()
            ->where('role', ShopRole::Owner->value)
            ->whereKeyNot($user->id)
            ->count();

        if ($remaining === 0) {
            throw new StaffActionUnavailableException('A shop must always have at least one owner.');
        }
    }
}
