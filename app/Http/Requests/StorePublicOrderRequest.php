<?php

namespace App\Http\Requests;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Keyed by slug, not id: StorefrontProductVariantResource deliberately
     * never exposes a variant's numeric id to the public (same reasoning
     * as the storefront link itself — see ProductService::
     * generateVariantSlug()'s docblock), so the id is never something a
     * storefront checkout could have sent in the first place. The slug
     * check is still the tenant-isolation boundary that matters most on
     * this route: there's no authenticated user here, so unlike the POS
     * checkout (StoreOrderRequest), there's no second, independent
     * cross-check behind it — only ResolveTenant's ambient tenant scope,
     * already bound by the time this validates. Same
     * ProductVariant::where('slug', ...)->exists() pattern as everywhere
     * else in this codebase applying the tenant global scope: a slug
     * belonging to another tenant fails validation here (and would fail
     * again in the service even if this check were ever bypassed).
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_slug' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ProductVariant::where('slug', $value)->exists()) {
                    $fail('One or more items are invalid.');
                }
            }],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:32'],
        ];
    }
}
