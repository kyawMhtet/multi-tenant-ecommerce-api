<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reason is required, not optional — the same rule as rejecting a transfer.
 * A shop locked out of its own admin and told only "suspended" can do nothing
 * but open a support ticket, and whoever picks that ticket up needs to know
 * why too.
 */
class SuspendShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
