<?php

namespace App\Sites\Central\Http\Requests\Dashboard;

use App\Modules\Marketing\Support\MarketingCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CreateOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['required', 'string', 'max:200'],
            // Format, reservation and uniqueness are checked by CreateOrganization.
            'slug' => ['required', 'string', 'max:63'],
            'business_type' => ['nullable', 'string', 'max:48'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'default_currency' => ['required', 'string', 'size:3', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
            // Optional free trial of one application, chosen on the public site.
            'trial_application' => ['nullable', 'string', Rule::in(array_column(app(MarketingCatalog::class)->trialable(), 'code'))],
        ];
    }

    /**
     * The organization's own fields, without the trial choice.
     *
     * @return array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string}
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string} */
        return Arr::except(parent::validated($key, $default), ['trial_application']);
    }

    public function trialApplication(): ?string
    {
        $code = parent::validated()['trial_application'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
