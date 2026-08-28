<?php

namespace App\Http\Requests;

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configures one payment method for the authenticated shop.
 *
 * `method` is validated against a fixed catalogue rather than accepted as
 * free text. The identifier drives the customer-facing label, whether a
 * gateway is involved, and whether proof-of-payment applies — so an
 * arbitrary string would produce a method nothing knows how to render or
 * process. `gateway` is deliberately NOT accepted from input at all: which
 * processor backs a method is a property of the method, resolved from the
 * catalogue, never something a client chooses.
 */
class UpsertPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Multipart sends every field as a string, and Laravel's 'boolean'
        // rule rejects the literal "true" — the same trap documented in
        // UpdateTenantRequest. A QR upload makes this request multipart, so
        // normalise before validating rather than after.
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

            // The shop's own payment QR. Same limits as every other upload
            // in this app; ImageUploadService re-encodes it, which also
            // strips whatever metadata the shop's phone attached.
            'qr' => ['sometimes', 'image', 'max:2048'],
            'remove_qr' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('qr'))],

            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
