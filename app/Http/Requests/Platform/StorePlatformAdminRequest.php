<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the validation in `php artisan platform:create-admin` — notably the
 * 12-character minimum, which is longer than the shop-side rule because these
 * accounts can read and settle money across every tenant on the platform.
 */
class StorePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Its own unique index, independent of users.email: the same
            // person may legitimately be both platform staff and the owner of
            // a shop on the platform.
            'email' => ['required', 'email', Rule::unique('platform_admins', 'email')],
            'password' => ['required', 'string', 'min:12'],
        ];
    }
}
