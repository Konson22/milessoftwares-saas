<?php

namespace App\Sites\Central\Http\Requests\Api;

use App\Sites\Central\Http\Requests\Dashboard\CreateOrganizationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * The dashboard's new business, from an application's own app: the trial is
 * that application's (not chosen), and the first branch comes with it so the
 * counter can open straight away.
 */
class CreateAppOrganizationRequest extends CreateOrganizationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['trial_application']),
            'branch_name' => ['required', 'string', 'max:255'],
            'branch_city' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * The organization's own fields, without the branch.
     *
     * @return array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string}
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string} */
        return Arr::except(parent::validated($key, $default), ['branch_name', 'branch_city']);
    }

    /**
     * @return array{name: string, city: string|null}
     */
    public function branch(): array
    {
        return [
            'name' => (string) $this->safe()->offsetGet('branch_name'),
            'city' => $this->safe()->offsetGet('branch_city') ?: null,
        ];
    }
}
