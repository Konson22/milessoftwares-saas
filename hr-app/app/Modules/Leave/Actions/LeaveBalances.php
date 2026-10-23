<?php

namespace App\Modules\Leave\Actions;

use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Days of leave per person, kind and year. The entitlement row is made the
 * first time it is needed: the type's days, plus unused days of the year
 * before up to the type's carry-over limit. What is left is always worked
 * out from the requests, never stored.
 */
class LeaveBalances
{
    /**
     * The year's entitlement, created if missing. Locked when asked, so that
     * requests and approvals for one person and kind happen one at a time.
     */
    public function entitlement(Employee $employee, LeaveType $type, int $year, bool $lock = false): LeaveEntitlement
    {
        $keys = ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year];
        $find = fn () => LeaveEntitlement::query()->where($keys)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        $entitlement = $find();
        if ($entitlement !== null) {
            return $entitlement;
        }

        $defaults = $this->defaults($employee, $type, $year);
        LeaveEntitlement::query()->createOrFirst($keys, ['days' => $defaults->days, 'carried_days' => $defaults->carried_days]);

        return $find() ?? throw new LogicException('Leave entitlement vanished.');
    }

    /**
     * What someone has of a kind in a year, without creating anything: a
     * year nobody has touched shows what its entitlement would start with.
     *
     * @return array{days: int, carried: int, taken: int, pending: int, remaining: int, available: int}
     */
    public function balance(Employee $employee, LeaveType $type, int $year): array
    {
        $entitlement = LeaveEntitlement::query()
            ->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])
            ->first();

        return $this->summarise($entitlement ?? $this->defaults($employee, $type, $year), $type);
    }

    /**
     * @return array{days: int, carried: int, taken: int, pending: int, remaining: int, available: int}
     */
    public function summarise(LeaveEntitlement $entitlement, LeaveType $type, ?string $exceptRequestId = null): array
    {
        $used = LeaveRequest::query()
            ->where('employee_id', $entitlement->employee_id)
            ->where('leave_type_id', $type->id)
            ->where('year', $entitlement->year)
            ->whereIn('status', LeaveStatus::holding())
            ->when($exceptRequestId !== null, fn ($query) => $query->whereKeyNot($exceptRequestId))
            ->selectRaw('status, sum(days) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $taken = (int) ($used[LeaveStatus::Approved->value] ?? 0);
        $pending = (int) ($used[LeaveStatus::Pending->value] ?? 0);
        $remaining = $entitlement->days + $entitlement->carried_days - $taken;

        return [
            'days' => $entitlement->days,
            'carried' => $entitlement->carried_days,
            'taken' => $taken,
            'pending' => $pending,
            'remaining' => $remaining,
            'available' => $remaining - $pending,
        ];
    }

    /**
     * Changes someone's days for a year (a part year, a longer-serving
     * person). What they carried over stays.
     */
    public function setDays(Employee $employee, LeaveType $type, int $year, int $days, User $by): LeaveEntitlement
    {
        return DB::transaction(function () use ($employee, $type, $year, $days, $by) {
            $entitlement = $this->entitlement($employee, $type, $year, lock: true);
            $entitlement->forceFill(['days' => $days, 'updated_by' => $by->id])->save();

            return $entitlement;
        });
    }

    /**
     * An unsaved entitlement as it would be made now.
     */
    private function defaults(Employee $employee, LeaveType $type, int $year): LeaveEntitlement
    {
        return (new LeaveEntitlement)->forceFill([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year,
            'days' => $type->days_per_year,
            'carried_days' => $this->carriedInto($employee, $type, $year),
        ]);
    }

    /**
     * Unused days of the year before, up to the type's limit. Nothing when
     * they had no entitlement that year or overspent it.
     */
    private function carriedInto(Employee $employee, LeaveType $type, int $year): int
    {
        if ($type->max_carry_over === 0) {
            return 0;
        }

        $previous = LeaveEntitlement::query()
            ->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year - 1])
            ->first();
        if ($previous === null) {
            return 0;
        }

        return max(0, min($type->max_carry_over, $this->summarise($previous, $type)['remaining']));
    }
}
