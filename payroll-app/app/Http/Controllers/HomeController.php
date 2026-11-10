<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\StatutoryRule;
use App\Models\Workspace;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What needs doing: people without pay, the latest payrolls, and whether
 * income tax and social security are set up.
 */
class HomeController extends Controller
{
    public function __invoke(StaffScope $scope): Response
    {
        $seesPay = $scope->organizationWide('payroll.pay.view');
        $today = CarbonImmutable::today();

        return Inertia::render('home', [
            'currency' => Workspace::current()?->currency,
            'headcount' => $scope->apply(Employee::query(), 'payroll.employees.view')->where('status', Employee::ACTIVE)->count(),
            'withoutPay' => $seesPay
                ? Employee::query()->where('status', Employee::ACTIVE)->whereDoesntHave('pay')->count()
                : null,
            'rulesInForce' => $seesPay ? StatutoryRule::inForceOn($today)?->name : null,
            'runs' => $seesPay ? PayrollRun::query()->orderByDesc('period_start')->limit(6)->get()->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'number' => $run->number,
                'period' => $run->label(),
                'status' => $run->status,
                'headcount' => $run->headcount,
                'netMinor' => $run->net_minor,
            ]) : [],
        ]);
    }
}
