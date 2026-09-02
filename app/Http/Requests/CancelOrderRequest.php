<?php

namespace App\Http\Requests;

use App\Services\Orders\CancellationReasonCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A reason is required, not optional. Cancellations are the thing a
     * shop most needs to look back on ("why do we keep losing orders?"),
     * and a reason nobody was made to give is a reason nobody gives —
     * leaving a column full of nulls that can never answer the question.
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', Rule::in(CancellationReasonCatalog::codes())],
            // Where the specifics go: which item was out of stock, what the
            // customer said on the phone. Required for 'other', since a
            // bare "Other" tells a future reader nothing at all.
            'cancellation_note' => ['required_if:cancellation_reason,other', 'nullable', 'string', 'max:1000'],
        ];
    }
}
