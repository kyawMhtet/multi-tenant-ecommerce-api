<?php

namespace App\Http\Requests\Auth;

use App\Rules\NotReservedSlug;
use App\Services\Tenants\SupportedCurrency;
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

            // Becomes the tenant's storefront subdomain, so it needs both the
            // reserved-word guard and a real DNS-label regex: "-shop" passes a
            // naive /^[a-z0-9-]+$/ but is an invalid hostname label, producing
            // a permanently broken subdomain rather than a merely ugly one.
            'slug' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('tenants', 'slug'),
                new NotReservedSlug,
            ],

            'owner_name' => ['required', 'string', 'max:255'],

            // Doubles as the login email — one owner, one identity. The DB
            // unique constraint is the real guard; this just gives a clean 422.
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],

            'owner_phone' => ['required', 'string', 'max:32'],

            // Chosen at signup because it can never change: money columns carry
            // no currency tag. Optional, defaulting to MMK, but a Thai shop must
            // be able to say so up front rather than silently getting Kyat.
            'currency' => ['sometimes', Rule::in(SupportedCurrency::codes())],

            // Business hours are wall-clock with no zone attached, so without
            // this a Bangkok shop's hours render as Yangon ones — 30 minutes out.
            'timezone' => ['sometimes', 'timezone'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
