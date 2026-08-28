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

            // Validated against what THIS shop has actually enabled, not a
            // hardcoded list — the same tenant-scoped-lookup trick used for
            // category_id elsewhere. TenantPaymentMethod carries
            // BelongsToTenant, so the ambient scope means a method
            // belonging to another shop simply isn't found, and a method
            // the shop has switched off is rejected by ->enabled().
            //
            // Required rather than defaulted: silently picking a payment
            // method on the customer's behalf is exactly the kind of
            // guess that ends with someone charged when they meant to pay
            // cash on delivery.
            'payment_method' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! TenantPaymentMethod::enabled()->where('method', $value)->exists()) {
                    $fail('The selected payment method is not available.');
                }
            }],

            // Validated against what THIS shop offers, not just the two
            // valid words — a delivery-only shop must reject a pickup order
            // even though 'pickup' is a legitimate value elsewhere. The
            // storefront shouldn't be offering it, but the API can't rely
            // on the client having read /public/shop correctly.
            'fulfillment_type' => ['required', Rule::in(['delivery', 'pickup']), function ($attribute, $value, $fail) {
                $tenant = app('tenant');
                $allowed = $value === 'pickup' ? $tenant->allows_pickup : $tenant->allows_delivery;

                if (! $allowed) {
                    $fail('This shop does not offer that fulfillment option.');
                }
            }],

            // full_address is the one required part, and deliberately the
            // only one: it's a single textarea a customer can fill on a
            // phone in seconds, and it always works — Myanmar addresses
            // lean on landmarks and townships in ways a fixed set of fields
            // can't capture ("behind the market, near the pagoda"). The
            // structured fields below are an optional refinement for
            // customers who want to be precise, not a hurdle for everyone.
            //
            // required_if rather than required: a pickup order genuinely
            // has no address, and forcing one would just get "n/a" typed
            // into it.
            // 'nullable' so a pickup order can send an explicit null rather than
            // having to omit the key — required_if still rejects a null when
            // the order is a delivery, since it treats null as absent.
            'delivery_address' => ['nullable', 'required_if:fulfillment_type,delivery', 'array'],
            'delivery_address.full_address' => ['required_if:fulfillment_type,delivery', 'string', 'max:1000'],
            'delivery_address.house_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'delivery_address.street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_address.township' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_address.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            // For the driver, not the address itself — "3rd floor", "call
            // on arrival", "gate is round the back".
            'delivery_address.note' => ['sometimes', 'nullable', 'string', 'max:500'],

            // The customer's screenshot of their transfer, for methods that
            // are paid out-of-band against the shop's own QR.
            //
            // Optional, not required, even for those methods: a customer
            // may legitimately place the order first and pay afterwards,
            // and refusing the order would just push them to abandon it.
            // The shop confirms payment either way, so a missing screenshot
            // costs them a message, not a sale.
            //
            // Nothing treats this as proof of anything — see the
            // proof_path migration. It's a claim for a human to judge.
            'payment_proof' => ['sometimes', 'image', 'max:2048'],
        ];
    }
}
