<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partial by design — an absent field means "leave unchanged", never "clear".
 *   logo (file)                    -> replaces; old file deleted after commit
 *   remove_logo: true              -> column nulled
 *   business_hours: null           -> hours cleared, social_links untouched
 *   social_links: {facebook: null} -> only that key removed
 *
 * slug is NOT accepted: changing it breaks every shared storefront link.
 * currency is NOT accepted: money columns carry no currency tag, so changing
 * it would retroactively reinterpret every historical total.
 */
class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The 'boolean' rule accepts [true, false, 0, 1, '0', '1'] — the STRING
     * "true" is not in that list, and a request carrying a logo is multipart
     * where every field arrives as a string. Rule::in(['0','1','true','false'])
     * is wrong too: validateIn string-casts, and a real JSON false becomes "".
     * Normalising first handles both. Garbage becomes null, which still fails
     * 'boolean', so it 422s rather than reading as false.
     */
    protected function prepareForValidation(): void
    {
        foreach (['remove_logo', 'remove_cover', 'allows_delivery', 'allows_pickup'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([
                    $flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        // A closed day is []. Multipart can't encode an empty array — the
        // closest is `business_hours[sun]=`, which arrives as "" and becomes
        // null via ConvertEmptyStringsToNull, so without this no multipart
        // save could express "closed on Sunday". Only PER-DAY nulls are
        // coerced; a null for the whole key still means "clear all hours".
        if (is_array($hours = $this->input('business_hours'))) {
            $this->merge([
                'business_hours' => array_map(fn ($day) => $day ?? [], $hours),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:255'],

            // Editable, unlike currency: changing it only reinterprets
            // wall-clock opening hours going forward, rewriting no history.
            'timezone' => ['sometimes', 'timezone'],

            // Partial like everything else, which is what makes the "at least
            // one" check in after() non-trivial — a request turning delivery
            // off may not mention pickup at all.
            'allows_delivery' => ['sometimes', 'boolean'],
            'allows_pickup' => ['sometimes', 'boolean'],

            // Editable like timezone: orders snapshot the fee they were
            // charged, so changes only affect future orders.
            'delivery_fee' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],

            'logo' => ['sometimes', 'image', 'max:2048'],
            'cover' => ['sometimes', 'image', 'max:2048'],

            // Sending a file and asking to remove it in the same request is
            // incoherent — rejected rather than resolved by silent
            // precedence, which would be a client bug that never surfaces.
            'remove_logo' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('logo'))],
            'remove_cover' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('cover'))],

            // All seven days required whenever hours are sent at all, so
            // "missing day" is never ambiguous with "closed". Capped at 2 so
            // split shifts (common here) need no migration later.
            'business_hours' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun',
                'required_array_keys:mon,tue,wed,thu,fri,sat,sun'],
            'business_hours.*' => ['array', 'list', 'max:2'],
            'business_hours.*.*' => ['array:open,close', 'required_array_keys:open,close'],
            'business_hours.*.*.open' => ['required', 'date_format:H:i'],
            'business_hours.*.*.close' => ['required', 'date_format:H:i'],

            'social_links' => ['sometimes', 'nullable',
                'array:facebook,instagram,tiktok,telegram,messenger,viber_phone'],
            // url:https is security, not pedantry: a free string rendered into
            // an <a href> is stored XSS — "javascript:alert(1)" passes a plain
            // string rule and executes on click on the public storefront.
            'social_links.facebook' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.instagram' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.tiktok' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.telegram' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.messenger' => ['nullable', 'string', 'max:255', 'url:https'],
            // A phone, not a URL — stored bare, the frontend builds the
            // viber://chat?number= link. A scheme-bearing string here would
            // reopen the href problem url:https just closed.
            'social_links.viber_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
        ];
    }

    /**
     * close > open can't be expressed with wildcard rules, so it's checked
     * here. Overnight hours are deliberately unsupported — a shop open past
     * midnight enters 23:59; a wrapping interval would burden every consumer.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Neither option means checkout has nothing valid to submit.
                // Merged against saved values because this is a partial update.
                $tenant = app('tenant');
                $delivery = $this->has('allows_delivery')
                    ? $this->boolean('allows_delivery')
                    : (bool) $tenant->allows_delivery;
                $pickup = $this->has('allows_pickup')
                    ? $this->boolean('allows_pickup')
                    : (bool) $tenant->allows_pickup;

                if (! $delivery && ! $pickup) {
                    $validator->errors()->add(
                        'allows_delivery',
                        'A shop must offer at least one of delivery or pickup.'
                    );
                }
            },
            function (Validator $validator) {
                foreach ((array) $this->input('business_hours', []) as $day => $intervals) {
                    if (! is_array($intervals)) {
                        continue;
                    }

                    foreach ($intervals as $index => $interval) {
                        $open = $interval['open'] ?? null;
                        $close = $interval['close'] ?? null;

                        if (is_string($open) && is_string($close) && $close <= $open) {
                            $validator->errors()->add(
                                "business_hours.{$day}.{$index}.close",
                                'The closing time must be after the opening time.'
                            );
                        }
                    }
                }
            },
        ];
    }
}
