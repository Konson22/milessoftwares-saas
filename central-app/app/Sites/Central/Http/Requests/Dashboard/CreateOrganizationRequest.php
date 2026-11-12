<?php

namespace App\Sites\Central\Http\Requests\Dashboard;

use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Organizations\Enums\OrganizationType;
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
            'legal_name' => ['nullable', 'string', 'max:200'],
            // Format, reservation and uniqueness are checked by CreateOrganization.
            'slug' => ['required', 'string', 'max:63'],
            'business_type' => ['nullable', 'string', 'max:48'],
            // Asked when Projects is among the trials: a donor, or an organization that runs the work.
            'organization_type' => ['nullable', Rule::enum(OrganizationType::class)],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'default_currency' => ['required', 'string', 'size:3', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
            // Optional free trials: one application chosen on the public site,
            // or several picked on the form.
            'trial_application' => ['nullable', 'string', Rule::in($this->trialable())],
            'trial_applications' => ['nullable', 'array', 'max:20'],
            'trial_applications.*' => ['string', 'distinct', Rule::in($this->trialable())],
        ];
    }

    /**
     * @return list<string>
     */
    private function trialable(): array
    {
        return array_column(app(MarketingCatalog::class)->trialable(), 'code');
    }

    /**
     * The organization's own fields, without the trial choice.
     *
     * @return array{display_name: string, legal_name?: string|null, slug: string, business_type?: string|null, organization_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string}
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{display_name: string, legal_name?: string|null, slug: string, business_type?: string|null, organization_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string} */
        return Arr::except(parent::validated($key, $default), ['trial_application', 'trial_applications']);
    }

    /**
     * The applications to try, in the order chosen, each once.
     *
     * @return list<string>
     */
    public function trialApplications(): array
    {
        $input = parent::validated();
        $codes = [$input['trial_application'] ?? null, ...($input['trial_applications'] ?? [])];

        return array_values(array_unique(array_filter($codes, fn ($code) => is_string($code) && $code !== '')));
    }
}
