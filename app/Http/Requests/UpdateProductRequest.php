<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Images are APPENDED, never replaced. Variant price and stock aren't here:
     * they touch margin reporting and the stock ledger, so they get their own
     * endpoints rather than folding into a generic product update.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value !== null && ! Category::find($value)) {
                    $fail('The selected category does not exist.');
                }
            }],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:2048'],
            // Must belong to THIS product, not just this tenant: ->images() on
            // the already-scoped route model rejects an id from another of the
            // tenant's own products too.
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer', function ($attribute, $value, $fail) {
                if (! $this->route('product')->images()->whereKey($value)->exists()) {
                    $fail('One or more images to remove are invalid.');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
