<?php

namespace App\Sites\Central\Http\Requests\Dashboard;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    /**
     * @return array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string}
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string} */
        return parent::validated($key, $default);
    }
}
