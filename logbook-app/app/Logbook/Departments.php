<?php

namespace App\Logbook;

use Miles\PlatformClient\Api\Department;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's departments, as Central keeps them (cached by the
 * platform client). Logbooks belong to one of them or to none.
 */
final class Departments
{
    /** @return list<Department> */
    public static function all(): array
    {
        try {
            return app(PlatformApi::class)->departments(app(CurrentTenant::class)->require()->id);
        } catch (PlatformUnavailable) {
            return [];
        }
    }

    /**
     * id => name of every department, archived ones included (a logbook may
     * still point at one).
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $department) {
            $names[$department->id] = $department->name;
        }

        return $names;
    }

    /**
     * Active departments a member may file a logbook under for the
     * permission: all of them, or those their role is limited to.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function options(string $permission, ?Member $member = null): array
    {
        $reach = ($member ?? Member::current())->reach($permission);

        $options = [];
        foreach (self::all() as $department) {
            if ($department->isActive() && ($reach === null || in_array($department->id, $reach, true))) {
                $options[] = ['id' => $department->id, 'name' => $department->name];
            }
        }
        usort($options, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $options;
    }
}
