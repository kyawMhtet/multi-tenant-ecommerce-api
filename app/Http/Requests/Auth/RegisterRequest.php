<?php

namespace App\Http\Requests\Auth;

use App\Rules\NotReservedSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'max:255'],

            // Becomes the tenant's subdomain (see config/cors.php's
            // per-tenant-subdomain pattern), so it needs the same
            // constraints as any subdomain segment plus the same
            // reserved-word guard product slugs already use — reused
            // as-is rather than duplicated, since it's built specifically
            // to be dropped into any Form Request with a slug field (see
            // its own docblock).
            // The regex is a DNS label, not just a charset: must start and
            // end alphanumeric, hyphens only in between. A slug like
            // "-shop" or "shop-" passes a naive /^[a-z0-9-]+$/ but is an
            // invalid hostname label, so it would produce a permanently
            // broken storefront subdomain rather than a merely ugly one.
            'slug' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('tenants', 'slug'),
                new NotReservedSlug,
            ],

            'owner_name' => ['required', 'string', 'max:255'],

            // Doubles as the login email — one owner, one identity, same
            // simplification the seeder and every test helper already
            // make (owner_email === the user's email). users.email has
            // its own DB-level unique constraint (see CLAUDE.md), but
            // validating here gives a clean 422 instead of a raw
            // constraint-violation exception.
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],

            'owner_phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
