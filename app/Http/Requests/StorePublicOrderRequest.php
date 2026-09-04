<?php

namespace App\Http\Requests;

use App\Models\ProductVariant;
use App\Models\TenantPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Keyed by slug, not id — a variant's numeric id is never exposed
     * publicly, so a storefront could never have sent one.
     *
     * The scoped ->exists() check IS the tenant boundary that matters here:
     * with no authenticated user there's no second cross-check behind it, only
     * the ambient scope. Another tenant's slug fails here, and would fail
     * again in the service if this were ever bypassed.
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_slug' => ['required', 'string', function ($attribute, $value, $fail) {
                // is_active on BOTH variant and product, matching
                // StorefrontProductService::findPublicVariant(). Without it the
                // write path stayed open to everything the read path hides:
                // slugs are permanent and public by design (they get pasted
                // into chat apps), so any link that ever circulated kept taking
                // orders for a product the shop had deliberately pulled.
                //
                // The tenant's own is_active is NOT re-checked here, unlike in
                // findPublicVariant() — this route is behind ResolveTenant,
                // which already refuses an inactive shop. There it's load-bearing
                // because no tenant is bound at all.
                $sellable = ProductVariant::where('slug', $value)
                    ->where('is_active', true)
                    ->whereHas('product', fn ($query) => $query->where('is_active', true))
                    ->exists();

                if (! $sellable) {
                    $fail('One or more items are invalid.');
                }
            }],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:32'],

            // Against what THIS shop enabled, not a hardcoded list. Required
            // rather than defaulted: silently picking a payment method for the
            // customer ends with someone charged when they meant to pay cash.
            'payment_method' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! TenantPaymentMethod::enabled()->where('method', $value)->exists()) {
                    $fail('The selected payment method is not available.');
                }
            }],

            // Against what THIS shop offers, not just the two valid words: a
            // delivery-only shop must reject pickup. The API can't rely on the
            // client having read /public/shop correctly.
            'fulfillment_type' => ['required', Rule::in(['delivery', 'pickup']), function ($attribute, $value, $fail) {
                $tenant = app('tenant');
                $allowed = $value === 'pickup' ? $tenant->allows_pickup : $tenant->allows_delivery;

                if (! $allowed) {
                    $fail('This shop does not offer that fulfillment option.');
                }
            }],

            // full_address is deliberately the ONLY required part: Myanmar
            // addresses lean on landmarks in ways fixed fields can't capture
            // ("behind the market, near the pagoda"). The structured fields
            // below are an optional refinement, not a hurdle.
            //
            // required_if, not required: a pickup order has no address, and
            // forcing one just gets "n/a" typed in. 'nullable' so pickup can
            // send an explicit null; required_if still rejects null on delivery.
            'delivery_address' => ['nullable', 'required_if:fulfillment_type,delivery', 'array'],
            'delivery_address.full_address' => ['required_if:fulfillment_type,delivery', 'string', 'max:1000'],
            'delivery_address.house_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'delivery_address.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_address.township' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_address.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            // For the driver — "3rd floor", "call on arrival".
            'delivery_address.note' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Optional even for QR methods: a customer may pay after ordering,
            // and refusing would just push them to abandon it. Nothing treats
            // this as proof — it's a claim for a human to judge.
            'payment_proof' => ['sometimes', 'image', 'max:2048'],
        ];
    }
}
