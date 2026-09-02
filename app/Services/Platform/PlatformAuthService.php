<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformAuthService
{
    /**
     * Deliberately parallel to AuthService::login(), but against a different
     * table — a platform admin is not a User and cannot be authenticated as
     * one.
     *
     * Note this lookup has no tenant dimension at all, and unlike
     * AuthService's it never will: platform staff belong to the platform.
     *
     * The inactive case returns the SAME generic error as bad credentials.
     * Saying "this account is disabled" tells an attacker the email is real
     * and that someone thought it worth revoking.
     *
     * @return array{0: PlatformAdmin, 1: string}
     */
    public function login(string $email, string $password): array
    {
        $admin = PlatformAdmin::where('email', $email)->first();

        if (! $admin || ! $admin->is_active || ! Hash::check($password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        // Named distinctly from a shop token so a leaked one is identifiable
        // in the personal_access_tokens table at a glance.
        $token = $admin->createToken('platform-token')->plainTextToken;

        return [$admin, $token];
    }

    public function logout(PlatformAdmin $admin): void
    {
        $admin->currentAccessToken()->delete();
    }
}
