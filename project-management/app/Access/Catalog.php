<?php

namespace App\Access;

/**
 * The permissions and roles this app declares to the platform
 * (platform/catalog.json, imported by the platform on deploy).
 */
final class Catalog
{
    /** @var array{permissions: array<string, array{name: string, sensitivity: int}>, roles: list<array{key: string, permissions: list<string>}>}|null */
    private static ?array $catalog = null;

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_keys(self::load()['permissions']);
    }

    /**
     * The permissions of one of the catalog's roles.
     *
     * @return list<string>
     */
    public static function rolePermissions(string $role): array
    {
        foreach (self::load()['roles'] as $definition) {
            if ($definition['key'] === $role) {
                return $definition['permissions'];
            }
        }

        return [];
    }

    public static function sensitivity(string $permission): int
    {
        return self::load()['permissions'][$permission]['sensitivity'] ?? 0;
    }

    /**
     * @return array{permissions: array<string, array{name: string, sensitivity: int}>, roles: list<array{key: string, permissions: list<string>}>}
     */
    private static function load(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        /** @var array{permissions?: list<array{key: string, name: string, sensitivity?: int}>, roles?: list<array{key: string, permissions?: list<string>}>} $json */
        $json = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true, flags: JSON_THROW_ON_ERROR);
        $permissions = [];
        foreach ($json['permissions'] ?? [] as $permission) {
            $permissions[$permission['key']] = ['name' => $permission['name'], 'sensitivity' => (int) ($permission['sensitivity'] ?? 1)];
        }
        $roles = array_map(fn (array $role) => ['key' => $role['key'], 'permissions' => $role['permissions'] ?? []], $json['roles'] ?? []);

        return self::$catalog = ['permissions' => $permissions, 'roles' => $roles];
    }
}
