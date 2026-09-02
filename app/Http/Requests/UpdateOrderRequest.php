<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * status/payment_status only — items, totals and source are snapshotted at
     * creation, and changing them isn't a status update, it's rewriting history.
     */
    public function rules(): array
    {
        return [
            // 'cancelled' and 'refunded' are deliberately NOT settable: both
            // carry required inputs, an audit trail and side effects a generic
            // field edit can't enforce. Leaving them here would be a second,
            // weaker path to the same state with no reason recorded.
            // Use POST /orders/{order}/cancel and /refund.
            'status' => ['sometimes', Rule::in(['pending', 'paid', 'processing', 'completed'])],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])],
        ];
    }
}
