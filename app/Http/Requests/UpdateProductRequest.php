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
     * Product fields, plus optionally more images (appended, never
     * replacing existing ones) and/or specific images to remove — both
     * only ever act on what's actually present in this one request, same
     * as every other field here ('sometimes'/'nullable', never assumed).
     * Editing a variant's price or stock touches margin reporting and
     * the stock ledger, which deserves its own dedicated endpoint rather
     * than being folded into a generic "update product" request — see
     * ProductService for details.
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
            // Each id must belong to *this* product specifically, not
            // just this tenant — $this->route('product') is already
            // tenant-scoped by route-model binding, so ->images() (a
            // plain child relation) is the precise check: it rejects an
            // id belonging to another of this tenant's own products the
            // same way it rejects one belonging to another tenant's.
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
