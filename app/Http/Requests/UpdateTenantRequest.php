<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shop profile update. Partial by design — every field is 'sometimes', and
 * an absent field always means "leave unchanged", never "clear".
 *
 * Field semantics:
 *   field absent                      -> unchanged
 *   logo (file)                       -> replaces; old file deleted after commit
 *   remove_logo: true                 -> column nulled; old file deleted after commit
 *   remove_logo: false                -> no-op, deliberately not an error
 *   business_hours: null              -> hours cleared, social_links untouched
 *   social_links: {facebook: null}    -> only facebook removed, other links untouched
 *
 * slug is NOT accepted: changing it silently breaks every previously shared
 * storefront link and any client caching X-Tenant-Slug. currency is NOT
 * accepted either — money columns carry no currency tag, so changing it
 * would retroactively reinterpret every historical order total.
 */
class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Laravel's 'boolean' rule accepts exactly [true, false, 0, 1, '0', '1'] —
     * the string "true" is NOT in that list. A request carrying a logo is
     * multipart, and every multipart field arrives as a string, so a raw
     * remove_logo=true would fail validation. The codebase's other
     * convention (Rule::in(['0','1','true','false'], used for query-string
     * filters) is also wrong here: validateIn string-casts its value, and a
     * real JSON false casts to "", so `remove_logo: false` in a JSON body
     * would 422. Normalising first means one rule handles JSON booleans and
     * multipart strings identically. Garbage becomes null, which still
     * fails 'boolean' (there's no 'nullable'), so it 422s rather than being
     * silently read as false.
     */
    protected function prepareForValidation(): void
    {
        foreach (['remove_logo', 'remove_cover'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([
                    $flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        // A closed day is an empty list. A JSON client sends [] directly,
        // but multipart has no way to encode an empty array — the closest
        // is `business_hours[sun]=`, which arrives as "" and is then turned
        // into null by the global ConvertEmptyStringsToNull middleware.
        // Without this, a multipart save (i.e. any save that also uploads a
        // logo) could not express "closed on Sunday" at all. Only per-day
        // nulls are coerced; a null for business_hours as a WHOLE still
        // means "clear all hours", which is a different, intentional thing.
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

            'logo' => ['sometimes', 'image', 'max:2048'],
            'cover' => ['sometimes', 'image', 'max:2048'],

            // Sending a file and asking to remove it in the same request is
            // incoherent — rejected rather than resolved by silent
            // precedence, which would be a client bug that never surfaces.
            'remove_logo' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('logo'))],
            'remove_cover' => ['sometimes', 'boolean', Rule::prohibitedIf(fn () => $this->hasFile('cover'))],

            // All seven days required whenever hours are submitted at all,
            // so "day missing" can never be ambiguous with "closed that day"
            // — closed is an empty list, the single representation of closed.
            // Capped at 2 intervals: one row today, split shifts (common
            // here — many shops close midday) without a migration later.
            'business_hours' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun',
                'required_array_keys:mon,tue,wed,thu,fri,sat,sun'],
            'business_hours.*' => ['array', 'list', 'max:2'],
            'business_hours.*.*' => ['array:open,close', 'required_array_keys:open,close'],
            'business_hours.*.*.open' => ['required', 'date_format:H:i'],
            'business_hours.*.*.close' => ['required', 'date_format:H:i'],

            'social_links' => ['sometimes', 'nullable',
                'array:facebook,instagram,tiktok,telegram,messenger,viber_phone'],
            // url:https is a security rule, not pedantry: a free string
            // rendered into an <a href> is stored XSS —
            // "javascript:alert(document.cookie)" passes a plain string rule
            // and executes on click on the public storefront.
            'social_links.facebook' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.instagram' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.tiktok' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.telegram' => ['nullable', 'string', 'max:255', 'url:https'],
            'social_links.messenger' => ['nullable', 'string', 'max:255', 'url:https'],
            // Viber is a phone number, not a URL. Keyed _phone so the type
            // is obvious, stored bare — the frontend builds the
            // viber://chat?number= link. Storing a scheme-bearing string
            // here would reintroduce the href problem url:https just closed.
            'social_links.viber_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
        ];
    }

    /**
     * close > open can't be expressed with wildcard rules ('after:' can't
     * reference business_hours.*.0.open), so it's checked here. This is
     * structural validation of the submitted shape, not business logic, so
     * the Form Request is the right layer — it keeps TenantService dumb.
     *
     * Overnight hours are deliberately unsupported: a shop open past
     * midnight enters 23:59. Supporting a wrapping interval would force
     * every consumer to handle it.
     */
    public function after(): array
    {
        return [
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
