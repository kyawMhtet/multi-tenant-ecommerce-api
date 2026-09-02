<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Recording that the shop has returned the money, not performing it.
     *
     * For manual methods the transfer happens in the shop's own banking
     * app and this platform never sees it — so there is nothing to validate
     * beyond the shop's own attestation. The note is where they put the
     * evidence they'd want later: the transfer reference, or "refunded in
     * cash when the driver collected the item".
     */
    public function rules(): array
    {
        return [
            'refund_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
