<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', function ($attribute, $value, $fail) {
                // find() applies the tenant scope, so another tenant's
                // category_id is rejected without comparing tenant_id by hand.
                if (! Category::find($value)) {
                    $fail('The selected category does not exist.');
                }
            }],
            // 'boolean' rejects the strings "true"/"false", which is all a
            // query string can send. Rule::in() accepts both forms; filters()
            // converts to a real bool.
            'is_active' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'low_stock' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The absent-vs-false distinction has to survive into the service:
     * is_active=false is a real filter (show only inactive), not "no filter",
     * so callers must use array_key_exists(), never empty()/??.
     */
    public function filters(): array
    {
        $filters = $this->validated();

        foreach (['is_active', 'low_stock'] as $field) {
            if (array_key_exists($field, $filters)) {
                $filters[$field] = $this->boolean($field);
            }
        }

        return $filters;
    }
}
