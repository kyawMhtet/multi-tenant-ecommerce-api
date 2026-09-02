<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

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
     * Tenant and owner User in one transaction — a half-created tenant with no
     * user isn't a valid state. tenant_id is set explicitly, not via
     * BelongsToTenant's hook: no tenant is bound yet, since it's what's being
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
                // Effectively permanent once orders exist — UpdateTenantRequest
                // refuses it. Defaulted, but settable so a Thai shop isn't
                // silently trading in Kyat.
                'currency' => $data['currency'] ?? 'MMK',
                'timezone' => $data['timezone'] ?? 'Asia/Yangon',
                'is_active' => true,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($data['password']),
                'role' => 'owner',
            ]);

            // Inside the same transaction as the tenant and owner: a shop
            // with no subscription row is not a valid state either. Every
            // entitlement gate reads this relation, so a tenant missing it
            // would be a shop that can neither write nor be billed.
            $this->subscriptions->startTrial($tenant);

            $token = $user->createToken('api-token')->plainTextToken;

            return [$user, $token];
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
