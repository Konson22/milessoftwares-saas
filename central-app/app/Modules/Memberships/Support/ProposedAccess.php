<?php

namespace App\Modules\Memberships\Support;

use Illuminate\Validation\Rule;

/**
 * The roles proposed while inviting or adding someone: any number, all at
 * the one scope chosen on the form. role_ids[] is the form's field; a single
 * role_id is still accepted.
 *
 * @phpstan-type ProposedRole array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}
 */
final class ProposedAccess
{
    public const MAX_ROLES = 20;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'role_id' => ['nullable', 'uuid'],
            'role_ids' => ['sometimes', 'array', 'list', 'max:'.self::MAX_ROLES],
            'role_ids.*' => ['uuid', 'distinct'],
            'scope_type' => ['required_with:role_id,role_ids', 'nullable', Rule::in(['organization', 'branch', 'department'])],
            'branch_id' => ['required_if:scope_type,branch', 'nullable', 'uuid'],
            'department_id' => ['required_if:scope_type,department', 'nullable', 'uuid'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<ProposedRole>
     */
    public static function fromInput(array $validated): array
    {
        $ids = array_values(array_unique(array_filter(
            [...(array) ($validated['role_ids'] ?? []), $validated['role_id'] ?? null],
            fn ($id) => is_string($id) && $id !== '',
        )));

        $scope = [
            'scope_type' => (string) ($validated['scope_type'] ?? ''),
            'branch_id' => isset($validated['branch_id']) ? (string) $validated['branch_id'] : null,
            'department_id' => isset($validated['department_id']) ? (string) $validated['department_id'] : null,
        ];

        return array_map(fn (string $id) => ['role_id' => $id, 'scope' => $scope], $ids);
    }
}
