<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\OrganizationMembership;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How an organization is arranged. A unit is archived, never deleted, and only
 * once no active unit sits inside it and no member belongs to it.
 */
final class OrgUnits
{
    /**
     * @param  array{kind: string, name: string, code?: string|null, parent_id?: string|null}  $details
     *
     * @throws ValidationException
     */
    public static function create(array $details, User $by): OrgUnit
    {
        return DB::transaction(function () use ($details, $by) {
            self::requireActiveParent($details['parent_id'] ?? null);

            $unit = new OrgUnit($details);
            $unit->created_by = $by->id;
            $unit->save();

            Audit::record('org_unit.created', $unit, ['name' => $unit->name, 'kind' => $unit->kind], actor: $by);

            return $unit;
        });
    }

    /**
     * @param  array{kind: string, name: string, code?: string|null, parent_id?: string|null}  $details
     *
     * @throws ValidationException
     */
    public static function update(OrgUnit $unit, array $details, User $by): void
    {
        DB::transaction(function () use ($unit, $details, $by) {
            $parentId = $details['parent_id'] ?? null;
            if ($parentId !== $unit->parent_id) {
                self::requireActiveParent($parentId);
                self::refuseCycle($unit, $parentId);
            }

            $fields = ['kind', 'name', 'code', 'parent_id'];
            $before = $unit->only($fields);
            $unit->fill(['parent_id' => $parentId] + $details)->save();

            Audit::change('org_unit.updated', $unit, $before, $unit->only($fields), actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function archive(OrgUnit $unit, User $by): void
    {
        DB::transaction(function () use ($unit, $by) {
            if (OrgUnit::query()->where('parent_id', $unit->id)->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['unit' => "{$unit->name} still has units inside it."]);
            }
            if (OrganizationMembership::query()->where('organization_id', $unit->organization_id)->where('org_unit_id', $unit->id)->exists()) {
                throw ValidationException::withMessages(['unit' => "{$unit->name} still has members."]);
            }

            $unit->forceFill(['archived_at' => now()])->save();
            Audit::record('org_unit.archived', $unit, ['name' => $unit->name], actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function restore(OrgUnit $unit, User $by): void
    {
        if ($unit->parent_id !== null && OrgUnit::query()->whereKey($unit->parent_id)->whereNotNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['unit' => 'Restore the unit it sits inside first.']);
        }

        $unit->forceFill(['archived_at' => null])->save();
        Audit::record('org_unit.restored', $unit, ['name' => $unit->name], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function assign(OrganizationMembership $membership, ?OrgUnit $unit, User $by): void
    {
        if ($unit?->archived_at !== null) {
            throw ValidationException::withMessages(['org_unit_id' => "{$unit->name} is archived."]);
        }

        $before = ['org_unit_id' => $membership->org_unit_id];
        $membership->forceFill(['org_unit_id' => $unit?->id])->save();

        Audit::change('member.unit_changed', $membership, $before, ['org_unit_id' => $membership->org_unit_id], ['user_id' => $membership->user_id], $membership->organization_id, $by);
    }

    /**
     * @throws ValidationException
     */
    private static function requireActiveParent(?string $parentId): void
    {
        if ($parentId !== null && ! OrgUnit::query()->whereKey($parentId)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Choose an active unit of this organization.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function refuseCycle(OrgUnit $unit, ?string $parentId): void
    {
        while ($parentId !== null) {
            if ($parentId === $unit->id) {
                throw ValidationException::withMessages(['parent_id' => 'A unit cannot sit inside itself.']);
            }
            $parentId = OrgUnit::query()->whereKey($parentId)->value('parent_id');
        }
    }
}
