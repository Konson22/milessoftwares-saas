<?php

namespace App\Modules\Applications\Catalog;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationCatalogVersion;
use App\Modules\Authorization\Enums\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Imports an application's role and permission catalog as a new immutable
 * version and makes it current. See database/catalogs/*.json for the format.
 *
 * Re-importing an existing version is a no-op when the content is identical
 * and an error when it differs: role assignments point at catalog rows, so a
 * published version must never change underneath them.
 */
final class CatalogImporter
{
    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws ValidationException
     * @throws CatalogConflict
     */
    public function import(array $definition): ApplicationCatalogVersion
    {
        $catalog = $this->validate($definition);
        $checksum = hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($catalog, $checksum): ApplicationCatalogVersion {
            $application = Application::query()->firstOrCreate(
                ['code' => $catalog['application']['code']],
                [
                    'name' => $catalog['application']['name'],
                    'category' => $catalog['application']['category'],
                    'kind' => $catalog['application']['kind'],
                    'status' => $catalog['application']['status'] ?? 'planned',
                ],
            );

            $existing = ApplicationCatalogVersion::query()
                ->where('application_id', $application->id)
                ->where('version', $catalog['version'])
                ->first();

            if ($existing !== null) {
                if ($existing->getAttribute('checksum') !== $checksum) {
                    throw new CatalogConflict("{$catalog['application']['code']} catalog {$catalog['version']} is already published with different content. Publish a new version instead.");
                }

                return $existing;
            }

            $version = new ApplicationCatalogVersion;
            $version->forceFill([
                'application_id' => $application->id,
                'version' => $catalog['version'],
                'status' => 'published',
                'published_at' => now(),
                'checksum' => $checksum,
            ])->save();

            $permissionIds = $this->insertPermissions($application->id, $version->id, $catalog['permissions']);
            $this->insertRoles($application->id, $version->id, $catalog['roles'], $permissionIds);
            $this->insertSodConstraints($version->id, $catalog['separation_of_duties'] ?? [], $permissionIds);
            $this->insertFeatures($application->id, $version->id, $catalog['features'] ?? []);

            ApplicationCatalogVersion::query()
                ->where('application_id', $application->id)
                ->whereKeyNot($version->id)
                ->where('status', 'published')
                ->update(['status' => 'retired']);

            $application->forceFill(['current_catalog_version_id' => $version->id])->save();

            return $version;
        });
    }

    /**
     * @param  list<array{key: string, name: string, description?: string|null, sensitivity: int, scopes: list<string>}>  $permissions
     * @return array<string, string> permission key => id
     */
    private function insertPermissions(string $applicationId, string $versionId, array $permissions): array
    {
        $ids = [];

        foreach ($permissions as $permission) {
            $id = (string) Str::uuid7();
            $ids[$permission['key']] = $id;

            DB::table('application_permissions')->insert([
                'id' => $id,
                'application_id' => $applicationId,
                'catalog_version_id' => $versionId,
                'key' => $permission['key'],
                'name' => $permission['name'],
                'description' => $permission['description'] ?? null,
                'sensitivity' => $permission['sensitivity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('application_permission_scope_types')->insert(array_map(
                fn (string $scope) => ['application_permission_id' => $id, 'scope_type' => $scope],
                $permission['scopes'],
            ));
        }

        return $ids;
    }

    /**
     * @param  list<array{key: string, name: string, description?: string|null, status?: string, limit_unit?: string|null}>  $features
     */
    private function insertFeatures(string $applicationId, string $versionId, array $features): void
    {
        foreach ($features as $feature) {
            DB::table('application_features')->insert([
                'id' => (string) Str::uuid7(),
                'application_id' => $applicationId,
                'catalog_version_id' => $versionId,
                'key' => $feature['key'],
                'name' => $feature['name'],
                'description' => $feature['description'] ?? null,
                'status' => $feature['status'] ?? 'available',
                'limit_unit' => $feature['limit_unit'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<array{key: string, name: string, description?: string|null, sensitivity: int, delegable: bool, scopes: list<string>, permissions: list<string>}>  $roles
     * @param  array<string, string>  $permissionIds
     */
    private function insertRoles(string $applicationId, string $versionId, array $roles, array $permissionIds): void
    {
        foreach ($roles as $role) {
            $id = (string) Str::uuid7();

            DB::table('application_roles')->insert([
                'id' => $id,
                'application_id' => $applicationId,
                'catalog_version_id' => $versionId,
                'key' => $role['key'],
                'name' => $role['name'],
                'description' => $role['description'] ?? null,
                'is_delegable' => $role['delegable'],
                'sensitivity' => $role['sensitivity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('application_role_scope_types')->insert(array_map(
                fn (string $scope) => ['application_role_id' => $id, 'scope_type' => $scope],
                $role['scopes'],
            ));

            DB::table('role_permission_mappings')->insert(array_map(
                fn (string $key) => [
                    'catalog_version_id' => $versionId,
                    'application_role_id' => $id,
                    'application_permission_id' => $permissionIds[$key],
                ],
                $role['permissions'],
            ));
        }
    }

    /**
     * @param  list<array{permissions: array{0: string, 1: string}, mode: string, description?: string|null}>  $constraints
     * @param  array<string, string>  $permissionIds
     */
    private function insertSodConstraints(string $versionId, array $constraints, array $permissionIds): void
    {
        foreach ($constraints as $constraint) {
            // The table stores each pair once, smaller id first.
            $pair = [$permissionIds[$constraint['permissions'][0]], $permissionIds[$constraint['permissions'][1]]];
            sort($pair);

            DB::table('sod_constraints')->insert([
                'id' => (string) Str::uuid7(),
                'catalog_version_id' => $versionId,
                'permission_a_id' => $pair[0],
                'permission_b_id' => $pair[1],
                'mode' => $constraint['mode'],
                'description' => $constraint['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{application: array{code: string, name: string, category: string, kind: string, status?: string}, version: string, permissions: list<array{key: string, name: string, description?: string|null, sensitivity: int, scopes: list<string>}>, roles: list<array{key: string, name: string, description?: string|null, sensitivity: int, delegable: bool, scopes: list<string>, permissions: list<string>}>, separation_of_duties?: list<array{permissions: array{0: string, 1: string}, mode: string, description?: string|null}>, features?: list<array{key: string, name: string, description?: string|null, status?: string, limit_unit?: string|null}>}
     *
     * @throws ValidationException
     */
    private function validate(array $definition): array
    {
        $scopes = array_map(fn (ScopeType $type) => $type->value, ScopeType::cases());

        $validator = Validator::make($definition, [
            'application.code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:32'],
            'application.name' => ['required', 'string', 'max:255'],
            'application.category' => ['required', 'string', 'max:32'],
            'application.kind' => ['required', Rule::in(['standalone', 'shared_module', 'industry', 'addon', 'platform'])],
            'application.status' => ['sometimes', Rule::in(['planned', 'beta', 'available', 'deprecated', 'retired'])],
            'version' => ['required', 'string', 'max:32'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'permissions.*.name' => ['required', 'string', 'max:255'],
            'permissions.*.description' => ['nullable', 'string'],
            'permissions.*.sensitivity' => ['required', 'integer', 'between:1,5'],
            'permissions.*.scopes' => ['required', 'array', 'min:1'],
            'permissions.*.scopes.*' => [Rule::in($scopes)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z][a-z0-9_]*$/'],
            'roles.*.name' => ['required', 'string', 'max:255'],
            'roles.*.description' => ['nullable', 'string'],
            'roles.*.sensitivity' => ['required', 'integer', 'between:1,5'],
            'roles.*.delegable' => ['required', 'boolean'],
            'roles.*.scopes' => ['required', 'array', 'min:1'],
            'roles.*.scopes.*' => [Rule::in($scopes)],
            'roles.*.permissions' => ['required', 'array', 'min:1'],
            'roles.*.permissions.*' => ['string'],
            'separation_of_duties' => ['sometimes', 'array'],
            'separation_of_duties.*.permissions' => ['required', 'array', 'size:2'],
            'separation_of_duties.*.permissions.*' => ['string'],
            'separation_of_duties.*.mode' => ['required', Rule::in(['block', 'warn'])],
            'separation_of_duties.*.description' => ['nullable', 'string'],
            // Plan features: optional, so catalogs without them keep their checksum.
            'features' => ['sometimes', 'array'],
            'features.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'features.*.name' => ['required', 'string', 'max:255'],
            'features.*.description' => ['nullable', 'string'],
            'features.*.status' => ['sometimes', Rule::in(['available', 'beta', 'deprecated'])],
            'features.*.limit_unit' => ['nullable', 'string', 'max:32'],
        ]);

        $validator->after(function ($validator) use ($definition): void {
            $known = array_column((array) ($definition['permissions'] ?? []), 'key');

            // Laravel's distinct rule compares across the whole wildcard, so
            // duplicates inside each list are checked here.
            foreach (['permissions' => ['scopes'], 'roles' => ['scopes', 'permissions']] as $section => $lists) {
                foreach ((array) ($definition[$section] ?? []) as $i => $entry) {
                    foreach ($lists as $list) {
                        $values = (array) ($entry[$list] ?? []);
                        if (count($values) !== count(array_unique($values))) {
                            $validator->errors()->add("{$section}.{$i}.{$list}", "Duplicate values in {$list}.");
                        }
                    }
                }
            }

            foreach ((array) ($definition['roles'] ?? []) as $i => $role) {
                foreach ((array) ($role['permissions'] ?? []) as $key) {
                    if (! in_array($key, $known, true)) {
                        $validator->errors()->add("roles.{$i}.permissions", "Role uses undeclared permission [{$key}].");
                    }
                }
            }

            // A feature belongs to the application declaring it.
            $code = (string) ($definition['application']['code'] ?? '');
            foreach ((array) ($definition['features'] ?? []) as $i => $feature) {
                if (! str_starts_with((string) ($feature['key'] ?? ''), $code.'.')) {
                    $validator->errors()->add("features.{$i}.key", "Feature keys start with [{$code}.].");
                }
            }

            foreach ((array) ($definition['separation_of_duties'] ?? []) as $i => $constraint) {
                $pair = (array) ($constraint['permissions'] ?? []);

                if (count(array_unique($pair)) !== 2) {
                    $validator->errors()->add("separation_of_duties.{$i}", 'A constraint needs two different permissions.');
                }

                foreach ($pair as $key) {
                    if (! in_array($key, $known, true)) {
                        $validator->errors()->add("separation_of_duties.{$i}", "Constraint uses undeclared permission [{$key}].");
                    }
                }
            }
        });

        /** @var array{application: array{code: string, name: string, category: string, kind: string, status?: string}, version: string, permissions: list<array{key: string, name: string, description?: string|null, sensitivity: int, scopes: list<string>}>, roles: list<array{key: string, name: string, description?: string|null, sensitivity: int, delegable: bool, scopes: list<string>, permissions: list<string>}>, separation_of_duties?: list<array{permissions: array{0: string, 1: string}, mode: string, description?: string|null}>, features?: list<array{key: string, name: string, description?: string|null, status?: string, limit_unit?: string|null}>} */
        return $validator->validate();
    }
}
