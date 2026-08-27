<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Query strings send the literal string "true"/"false", which
            // Laravel's plain 'boolean' rule rejects (see IndexProductRequest
            // for the same fix) — Rule::in() accepts both string forms.
            'unread_only' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Unlike is_active on the products filter, there's no meaningful
     * "explicitly show read-only" mode here — absent and false both just
     * mean "show everything" — so a plain default-to-false cast is
     * enough, no need to preserve an absent-vs-false distinction.
     */
    public function filters(): array
    {
        return [
            'unread_only' => $this->boolean('unread_only'),
            'per_page' => $this->validated('per_page'),
        ];
    }
}
