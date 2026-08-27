<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @return array{0: User, 1: string}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return [$user, $token];
    }

    /**
     * Self-serve shop signup: creates the Tenant and its first (owner)
     * User in one transaction, then logs them straight in — a half-created
     * tenant with no user (or vice versa) isn't a valid state, and asking
     * a brand-new signup to immediately log in separately would be a
     * pointless extra step. tenant_id is set explicitly here, not via
     * BelongsToTenant's auto-fill hook, since there is no tenant bound in
     * the container yet at this point — the tenant IS what's being
     * created.
     *
     * @return array{0: User, 1: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['shop_name'],
                'slug' => $data['slug'],
                'owner_name' => $data['owner_name'],
                'owner_email' => $data['owner_email'],
                'owner_phone' => $data['owner_phone'],
                'is_active' => true,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['password']),
                'role' => 'owner',
            ]);

            $token = $user->createToken('api-token')->plainTextToken;

            return [$user, $token];
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
