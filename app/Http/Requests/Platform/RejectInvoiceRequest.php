<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reason is required, not optional. A shop told only "rejected" cannot act
 * on it and will open a support ticket asking why — which costs more than
 * typing the sentence would have.
 *
 * Free text here, unlike CancellationReasonCatalog, because this is a message
 * to one shop about one transfer rather than something anyone will ever want
 * to GROUP BY.
 */
class RejectInvoiceRequest extends FormRequest
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
