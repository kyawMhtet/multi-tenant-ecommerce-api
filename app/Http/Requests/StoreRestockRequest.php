<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A track_stock=false variant (made-to-order items) has
            // nothing meaningful to restock — rejected explicitly here
            // rather than silently no-op'd, since unlike a sale's
            // automatic stock deduction, restocking is always a
            // deliberate user action.
            'quantity' => ['required', 'numeric', 'min:0.01', function ($attribute, $value, $fail) {
                if (! $this->route('variant')->track_stock) {
                    $fail('This variant does not track stock and cannot be restocked.');
                }
            }],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
