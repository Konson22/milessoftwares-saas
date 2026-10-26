<?php

namespace App\Actions;

use App\Models\Staff;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Events\Outbox;

/**
 * Staff joining the school (STF-00001). HR hears of each one
 * (hr.employee.joined) with organizational facts only: pay, bank and
 * identity details are entered in HR itself.
 */
class Staffing
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function hire(array $details): Staff
    {
        return DB::transaction(function () use ($details) {
            $staff = Staff::query()->create([
                ...$details,
                'number' => $this->numbers->next('staff', 'STF'),
                'active' => true,
            ]);

            $this->outbox->record('hr', 'hr.employee.joined', 1, "staff:{$staff->id}:joined", [
                'employee_reference' => $staff->number,
                'display_name' => $staff->name,
                'work_email' => $staff->email,
                'membership_id' => null,
                'position_id' => null,
                // School departments are not HR's departments.
                'department_id' => null,
                'branch_id' => null,
                'started_on' => $staff->started_on->toDateString(),
            ]);

            return $staff;
        });
    }
}
