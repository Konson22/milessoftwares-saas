<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Employees\Actions\Staff;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Actions\LeaveRequests;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\PublicHoliday;
use App\Platform\SetUpWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * Staff and leave for the demo business central's DemoBusinessSeeder made
 * (DEMO_BUSINESS_FILE), a water utility, entered through the app's services.
 */
class DemoBusinessSeeder extends Seeder
{
    /**
     * Team key or null, name, department, job title, hired on, manager (index
     * of an earlier row) and contract type.
     */
    private const STAFF = [
        [null, 'Daniel Akol', 'ADM', 'Managing Director', '2018-02-01', null, 'permanent'],
        ['hr_manager', 'Rebecca Kiden', 'HRM', 'Human Resources Manager', '2019-05-13', 0, 'permanent'],
        ['hr_officer', 'James Lokuda', 'HRM', 'Human Resources Officer', '2022-03-07', 1, 'permanent'],
        ['finance_manager', 'Mary Lado', 'FIN', 'Finance Manager', '2019-01-14', 0, 'permanent'],
        ['chief_accountant', 'Abuk Garang', 'FIN', 'Chief Accountant', '2020-06-01', 3, 'permanent'],
        ['accountant', 'Peter Modi', 'FIN', 'Accountant', '2023-02-06', 4, 'permanent'],
        ['finance_officer', 'Oliver Wani', 'FIN', 'Finance Officer', '2024-01-08', 3, 'permanent'],
        ['cashier', 'Chol Deng', 'FIN', 'Cashier', '2024-08-05', 3, 'permanent'],
        ['auditor', 'Nyandeng Mabior', 'ADM', 'Internal Auditor', '2021-09-06', 0, 'permanent'],
        ['system_administrator', 'Deng Ajak', 'ADM', 'Systems Administrator', '2023-07-03', 0, 'permanent'],
        ['line_manager', 'Simon Tombe', 'OPS', 'Operations Manager', '2019-11-04', 0, 'permanent'],
        [null, 'Wilson Gore', 'OPS', 'Pump Station Supervisor', '2021-02-15', 10, 'permanent'],
        [null, 'Moses Lomoro', 'OPS', 'Pump Operator', '2022-10-03', 11, 'permanent'],
        [null, 'Ajak Bior', 'OPS', 'Pump Operator', '2025-04-07', 11, 'fixed_term'],
        [null, 'Sarah Nyoka', 'TRT', 'Water Quality Chemist', '2020-08-17', 10, 'permanent'],
        [null, 'Stella Gune', 'TRT', 'Laboratory Technician', '2024-03-04', 14, 'permanent'],
        ['projects_manager', 'Anna Nyibol', 'NET', 'Network Engineer', '2020-01-20', 0, 'permanent'],
        ['project_member', 'Samuel Malish', 'NET', 'Field Engineer', '2023-05-02', 16, 'permanent'],
        [null, 'Kenyi Lasu', 'NET', 'Plumber', '2022-06-13', 17, 'permanent'],
        [null, 'Mawien Akech', 'NET', 'Plumber', '2026-08-03', 17, 'casual'],
        [null, 'Juan Tongun', 'COM', 'Commercial Manager', '2019-03-11', 0, 'permanent'],
        [null, 'Betty Kaku', 'COM', 'Billing Officer', '2023-01-16', 20, 'permanent'],
        [null, 'Nelson Yugu', 'COM', 'Meter Reader', '2024-02-05', 20, 'permanent'],
        [null, 'Rose Ropani', 'COM', 'Billing Intern', '2026-07-06', 21, 'internship'],
        ['logistics_manager', 'Gabriel Ladu', 'FLT', 'Fleet and Tanker Manager', '2020-04-06', 0, 'permanent'],
        ['dispatcher', 'Grace Poni', 'FLT', 'Tanker Dispatcher', '2023-10-02', 24, 'permanent'],
        ['driver', 'Santino Lual', 'FLT', 'Tanker Driver', '2022-01-10', 25, 'permanent'],
        ['fleet_officer', 'Michael Taban', 'FLT', 'Fleet Officer', '2024-05-06', 24, 'permanent'],
        ['school_head', 'Isaac Bol', 'TRN', 'Head of the Training Institute', '2021-01-11', 0, 'permanent'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            DB::transaction(function () use ($data, $organization) {
                app(SetUpWorkspace::class)->provision($organization);

                $team = [];
                foreach ($data['users'] as $key => $user) {
                    $member = new User;
                    $member->forceFill($user)->save();
                    $team[$key] = $member;
                }

                $this->seedStaff($data, $team);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, User>  $team
     */
    private function seedStaff(array $data, array $team): void
    {
        foreach ([
            ['2026-01-01', "New Year's Day"], ['2026-01-09', 'Peace Agreement Day'], ['2026-05-16', 'Liberation Army Day'],
            ['2026-07-09', 'Independence Day'], ['2026-07-30', "Martyrs' Day"], ['2026-12-25', 'Christmas Day'], ['2026-12-28', 'Republic Day'],
        ] as [$date, $name]) {
            PublicHoliday::query()->create(['date' => $date, 'name' => $name]);
        }

        $hr = $team['hr_officer'];
        $staff = app(Staff::class);
        /** @var list<Employee> $employees */
        $employees = [];

        foreach (self::STAFF as [$key, $name, $department, $title, $hiredOn, $manager, $type]) {
            [$first, $last] = explode(' ', $name, 2);
            $membership = $key !== null ? $data['memberships'][$key] : null;
            $employee = $staff->hire([
                'first_name' => $first,
                'last_name' => $last,
                'work_email' => $key !== null ? $data['users'][$key]['email'] : null,
                'work_phone' => '+211 920 '.str_pad((string) (100000 + count($employees) * 137), 6, '0', STR_PAD_LEFT),
                'manager_id' => $manager !== null ? $employees[$manager]->id : null,
                'branch_id' => $data['branch_id'],
                'department_id' => $data['departments'][$department],
                'job_title' => $title,
                'hired_on' => $hiredOn,
            ], [
                'contract_type' => $type,
                'ends_on' => match ($type) {
                    'fixed_term' => '2027-03-31',
                    'casual' => '2027-01-31',
                    'internship' => '2026-12-31',
                    default => null,
                },
                'probation_ends_on' => $hiredOn >= '2026-01-01' ? date('Y-m-d', (int) strtotime($hiredOn.' +3 months')) : null,
                'notice_days' => 30,
            ], $hr);

            $employee->forceFill(['membership_id' => $membership])->save();

            $employees[] = $employee;
        }

        $this->seedLeave($employees, $team);
    }

    /**
     * @param  list<Employee>  $employees
     * @param  array<string, User>  $team
     */
    private function seedLeave(array $employees, array $team): void
    {
        $leave = app(LeaveRequests::class);
        $annual = LeaveType::query()->where('name', 'Annual leave')->sole();
        $sick = LeaveType::query()->where('name', 'Sick leave')->sole();
        $unpaid = LeaveType::query()->where('name', 'Unpaid leave')->sole();
        $by = $team['hr_officer'];
        $manager = $team['line_manager'];

        // Taken this year.
        foreach ([
            [12, $annual, '2026-02-09', '2026-02-20', 'Family visit to Torit'],
            [15, $sick, '2026-03-16', '2026-03-18', 'Malaria'],
            [18, $annual, '2026-04-06', '2026-04-17', 'Annual leave'],
            [21, $annual, '2026-06-08', '2026-06-19', 'Family wedding in Yei'],
            [5, $annual, '2026-08-03', '2026-08-14', 'Annual leave'],
            [26, $sick, '2026-09-07', '2026-09-08', 'Clinic visit'],
            [22, $annual, '2026-10-12', '2026-10-16', 'Annual leave'],
        ] as [$index, $type, $from, $to, $reason]) {
            $leave->approve($leave->request($employees[$index], $type, $from, $to, $reason, $by), 'Cover arranged', $manager);
        }

        // Away now.
        $leave->approve($leave->request($employees[14], $annual, '2026-11-02', '2026-11-13', 'Annual leave', $by), 'Lab cover arranged', $manager);

        // Waiting for a decision.
        $leave->request($employees[11], $annual, '2026-11-23', '2026-12-04', 'Family visit to Wau', $by);
        $leave->request($employees[17], $annual, '2026-12-14', '2026-12-24', 'Christmas with family', $by);
        $leave->request($employees[25], $sick, '2026-11-05', '2026-11-06', 'Dental surgery', $by);

        // Refused and withdrawn, for the history.
        $leave->reject($leave->request($employees[12], $annual, '2026-07-06', '2026-07-17', 'Annual leave', $by), 'Two operators already away that fortnight', $manager);
        $leave->cancel($leave->request($employees[6], $unpaid, '2026-09-14', '2026-09-15', 'Personal errand', $by), $by);
    }
}
