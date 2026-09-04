<?php

namespace App\Http\Requests;

use App\Services\Tenants\ShopRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Email is not editable: it is the login identity, and changing it locks
     * the person out of an account they may already be signed into.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(ShopRole::values())],
        ];
    }
}
