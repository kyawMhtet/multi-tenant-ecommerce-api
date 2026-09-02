<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * max:2048 (KB) matches every other upload here, and ImageUploadService
 * re-encodes whatever arrives — which also strips the metadata the shop
 * owner's phone attached to the screenshot.
 */
class UploadPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proof' => ['required', 'image', 'max:2048'],
        ];
    }
}
