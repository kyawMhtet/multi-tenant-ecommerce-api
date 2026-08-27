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
                // Category::find() applies the tenant global scope, so this
                // rejects a category_id belonging to another tenant without
                // ever comparing tenant_id by hand.
                if (! Category::find($value)) {
                    $fail('The selected category does not exist.');
                }
            }],
            // Laravel's 'boolean' rule only accepts the literal types
            // true/false/0/1/"0"/"1" — a query string always sends "true"/
            // "false" as plain strings, which it rejects. Rule::in()
            // accepts both forms; filters() below converts whichever one
            // arrived into a real bool via $this->boolean().
            'is_active' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'low_stock' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * validated() alone leaves is_active/low_stock as the raw query-string
     * string ("1"/"0"/"true"/"false"), not a real bool, and — more
     * importantly — omits the key entirely when the client didn't send it.
     * That absent-vs-false distinction has to survive into the array the
     * service receives: is_active=false is a real filter (show only
     * inactive products), not "no filter", so callers must check with
     * array_key_exists(), never empty()/??.
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
