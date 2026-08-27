<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A trimmed-down public counterpart to IndexProductRequest — no is_active
 * (a public listing is always active-only, never a toggle) and no
 * low_stock (an inventory concept, not a customer-facing one). per_page is
 * capped lower than the admin endpoint's: this drives a storefront grid,
 * not a management table.
 */
class IndexPublicProductRequest extends FormRequest
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
                // Category::find() applies the ambient tenant scope (bound
                // by the 'tenant' middleware from X-Tenant-Slug), so this
                // rejects a category belonging to another tenant the same
                // way the admin filter does.
                if (! Category::find($value)) {
                    $fail('The selected category does not exist.');
                }
            }],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
