<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * An organization's name and defaults, as set up (OrganizationController)
 * and as changed later (Admin\SettingsController): one set of rules.
 */
trait ValidatesOrganizationDetails
{
    /**
     * @param  array<string, mixed>  $rules  extra or overriding rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    protected function organizationDetails(Request $request, array $rules = [], array $messages = []): array
    {
        $request->merge([
            'currency' => mb_strtoupper(trim($request->string('currency')->toString())),
            'country_code' => mb_strtoupper(trim($request->string('country_code')->toString())) ?: null,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all'],
            ...$rules,
        ], [
            'currency.regex' => 'A three-letter currency code, e.g. USD, KES, SSP.',
            'country_code.regex' => 'Two letters, e.g. SS, KE.',
            ...$messages,
        ]);
    }
}
