<?php

namespace App\Http\Controllers;

use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff at a glance, for the staff the member can see: headcount,
 * who joined and left this month, and contracts and probations ending soon.
 */
class HomeController extends Controller
{
    public const SOON_DAYS = 30;

    public function __invoke(StaffScope $scope): Response
    {
        if (! $scope->holds('hr.employees.view')) {
            return Inertia::render('home', ['summary' => null]);
        }

        $today = CarbonImmutable::today();
        $soon = $today->addDays(self::SOON_DAYS);
        $visible = fn () => $scope->apply(Employee::query(), 'hr.employees.view');
        $active = fn () => $visible()->where('status', Employee::ACTIVE);

        $ending = Contract::query()
            ->where('status', Contract::CURRENT)
            ->whereIn('employee_id', $active()->select('id'))
            ->where(fn ($query) => $query
                ->whereBetween('ends_on', [$today->toDateString(), $soon->toDateString()])
                ->orWhereBetween('probation_ends_on', [$today->toDateString(), $soon->toDateString()]))
            ->with('employee')
            ->limit(200)
            ->get()
            ->map(function (Contract $contract) use ($today, $soon) {
                $probation = $contract->probation_ends_on !== null && $contract->probation_ends_on->betweenIncluded($today, $soon);

                return [
                    'employeeId' => $contract->employee_id,
                    'name' => $contract->employee->displayName(),
                    'what' => $probation ? 'Probation ends' : 'Contract ends',
                    'on' => ($probation ? $contract->probation_ends_on : $contract->ends_on)?->toDateString(),
                ];
            })
            ->sortBy('on')
            ->take(20)
            ->values();

        return Inertia::render('home', [
            'summary' => [
                'headcount' => $active()->count(),
                'joinedThisMonth' => $visible()->where('hired_on', '>=', $today->startOfMonth()->toDateString())->count(),
                'leftThisMonth' => $visible()->where('status', Employee::LEFT)->where('left_on', '>=', $today->startOfMonth()->toDateString())->count(),
                'soonDays' => self::SOON_DAYS,
                'endingSoon' => $ending,
            ],
        ]);
    }
}
