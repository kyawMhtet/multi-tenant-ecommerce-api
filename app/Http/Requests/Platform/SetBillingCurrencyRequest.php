<?php

namespace App\Http\Requests\Platform;

use App\Services\Billing\BillingCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `currency` is nullable on purpose: null RESTORES the default of following
 * the shop's own selling currency, rather than meaning "no billing currency".
 * Most shops should be null, which is why it is the reset value rather than
 * an error.
 */
class SetBillingCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['present', 'nullable', 'string', Rule::in(BillingCurrency::codes())],
        ];
    }
}
