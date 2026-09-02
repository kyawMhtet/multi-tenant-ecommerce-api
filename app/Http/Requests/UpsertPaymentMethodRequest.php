<?php

namespace App\Http\Requests;

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `method` is validated against the catalogue, not accepted as free text — an
 * arbitrary string would produce a method nothing knows how to render.
 * `gateway` is NOT accepted from input at all: which processor backs a method
 * is a property of the method, never a client's choice.
 */
class UpsertPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Multipart sends every field as a string and 'boolean' rejects the
        // literal "true" — same trap as UpdateTenantRequest.
        foreach (['is_enabled', 'remove_qr'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([
                    $flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(PaymentMethodCatalog::codes())],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],

            // ImageUploadService re-encodes it, which also strips whatever
            // metadata the shop's phone attached.
            'qr' => ['sometimes', 'image', 'max:2048'],
            'remove_qr' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('qr'))],

            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
