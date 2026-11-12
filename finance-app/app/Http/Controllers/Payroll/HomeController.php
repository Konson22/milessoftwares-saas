<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\StatutoryRule;
use App\Models\Workspace;
use App\Payroll\Support\StaffScope;
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
        $seesPay = $scope->organizationWide('finance.payroll.pay.view');
        $today = CarbonImmutable::today();

        return Inertia::render('payroll/home', [
            'currency' => Workspace::current()?->currency,
            'headcount' => $scope->apply(Employee::query(), 'finance.payroll.employees.view')->where('status', Employee::ACTIVE)->count(),
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
