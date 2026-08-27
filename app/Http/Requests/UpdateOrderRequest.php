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
     * status/payment_status only — everything else about an order
     * (items, totals, source) is fixed at creation time and snapshotted;
     * changing them after the fact isn't a "status update," it's rewriting
     * history. Same enum values as IndexOrderRequest's status filter and
     * the orders migration.
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['pending', 'paid', 'processing', 'completed', 'cancelled', 'refunded'])],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid', 'refunded'])],
        ];
    }
}
