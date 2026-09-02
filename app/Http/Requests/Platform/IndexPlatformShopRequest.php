<?php

namespace App\Http\Requests\Platform;

use App\Services\Billing\PlanCatalog;
use App\Services\Tenants\SupportedCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters are validated against the catalogues rather than passed through, so
 * a typo returns a 422 the reviewer can see instead of an empty list they'd
 * read as "no such shops".
 */
class IndexPlatformShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('suspended')) {
            $this->merge([
                'suspended' => filter_var($this->input('suspended'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'plan' => ['sometimes', 'nullable', Rule::in(PlanCatalog::codes())],
            'status' => ['sometimes', 'nullable', Rule::in(['trialing', 'active', 'past_due', 'cancelled'])],
            'rail' => ['sometimes', 'nullable', Rule::in(['stripe', 'manual'])],
            // The SELLING currency, which is what the tenants table holds.
            'currency' => ['sometimes', 'nullable', Rule::in(SupportedCurrency::codes())],
            'suspended' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['search', 'plan', 'status', 'rail', 'currency', 'suspended']);
    }
}
