<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff of the platform. NOT a User, and never given a tenant_id — see the
 * migration for why that distinction is the whole security model.
 *
 * A caution about Sanctum that is easy to get wrong: personal access tokens
 * are POLYMORPHIC, and Sanctum's guard authenticates whatever model a token
 * points at without consulting the guard's configured provider. So a token
 * issued here WILL satisfy `auth:sanctum` on a tenant route. Defining a
 * separate guard in config/auth.php does not change that and would only give
 * a false sense of separation.
 *
 * The isolation is therefore enforced by explicit type checks at both
 * doors — EnsurePlatformAdmin on the way in here, and an instanceof User
 * check in ResolveTenant on the way into tenant routes — rather than by
 * configuration.
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
