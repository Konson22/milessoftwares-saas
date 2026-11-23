<?php

namespace App\Procurement;

use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Department;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Throwable;

/**
 * Departments are the organization's, kept in the platform (Central):
 * Procurement never keeps its own list. Records store the department id and
 * its name at the time, so history reads the same after a rename.
 */
final class Departments
{
    /** @var array<string, list<Department>> */
    private static array $cache = [];

    /**
     * Active departments, for pickers.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name], self::all());
    }

    /**
     * The department's name, or a validation error when it is not one of
     * the organization's active departments. Null in, null out.
     *
     * @throws ValidationException
     */
    public static function name(?string $id, string $field = 'department_id'): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach (self::all() as $department) {
            if ($department->id === $id) {
                return $department->name;
            }
        }

        throw ValidationException::withMessages([$field => 'Choose one of the organization\'s departments.']);
    }

    /**
     * @return list<Department>
     */
    private static function all(): array
    {
        $organization = app(CurrentTenant::class)->require()->id;

        if (! isset(self::$cache[$organization])) {
            try {
                $departments = app(PlatformApi::class)->departments($organization);
            } catch (PlatformUnavailable) {
                $departments = [];
            } catch (Throwable $e) {
                report($e);
                $departments = [];
            }
            self::$cache[$organization] = array_values(array_filter($departments, fn (Department $d) => $d->isActive()));
        }

        return self::$cache[$organization];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
