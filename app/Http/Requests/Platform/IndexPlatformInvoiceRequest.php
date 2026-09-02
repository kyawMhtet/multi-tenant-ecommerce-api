<?php

namespace App\Http\Requests\Platform;

use App\Services\Billing\BillingCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPlatformInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'paid', 'failed', 'void'])],
            'rail' => ['sometimes', 'nullable', Rule::in(['stripe', 'manual'])],
            // The BILLING currency — what the shop pays us in, which is not
            // necessarily what it sells in.
            'currency' => ['sometimes', 'nullable', Rule::in(BillingCurrency::codes())],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['status', 'rail', 'currency', 'tenant_id', 'from', 'to']);
    }
}
