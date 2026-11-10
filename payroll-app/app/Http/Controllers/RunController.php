<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Models\Workspace;
use App\Payroll\Runs;
use App\Support\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Monthly payrolls and their payslips. Every page here shows pay, so the
 * routes require payroll.pay.view (organization-wide) at least.
 */
class RunController extends Controller
{
    public function __construct(private readonly CurrentAccess $access) {}

    public function index(): Response
    {
        return Inertia::render('runs/index', [
            'currency' => Workspace::current()?->currency,
            'runs' => PayrollRun::query()->orderByDesc('period_start')->limit(60)->get()->map(fn (PayrollRun $run) => [
                ...$this->summary($run),
            ]),
            'defaultMonth' => CarbonImmutable::today()->format('Y-m'),
            'defaultPayDate' => CarbonImmutable::today()->endOfMonth()->toDateString(),
            'canPrepare' => $this->can('payroll.runs.prepare'),
        ]);
    }

    public function store(Request $request, Runs $runs): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'pay_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $run = $runs->prepare(CarbonImmutable::createFromFormat('!Y-m', $validated['month']) ?: CarbonImmutable::today(), CarbonImmutable::parse($validated['pay_date']), $this->user($request), $validated['note'] ?? null);

        return to_route('runs.show', $run)->with('success', "Payroll {$run->number} prepared for {$run->label()}.");
    }

    public function show(Request $request, PayrollRun $run, Structure $structure): Response
    {
        $names = $structure->names();
        $run->load(['preparer:id,name', 'approver:id,name', 'payer:id,name']);
        $user = $this->user($request);

        return Inertia::render('runs/show', [
            'currency' => Workspace::current()?->currency,
            'run' => [
                ...$this->summary($run),
                'periodStart' => $run->period_start->toDateString(),
                'periodEnd' => $run->period_end->toDateString(),
                'payDate' => $run->pay_date->toDateString(),
                'earningsMinor' => $run->earnings_minor,
                'incomeTaxMinor' => $run->income_tax_minor,
                'socialSecurityEmployeeMinor' => $run->social_security_employee_minor,
                'socialSecurityEmployerMinor' => $run->social_security_employer_minor,
                'otherDeductionsMinor' => $run->other_deductions_minor,
                'preparedBy' => $run->preparer->name,
                'preparedAt' => $run->prepared_at->toIso8601String(),
                'approvedBy' => $run->approver?->name,
                'approvedAt' => $run->approved_at?->toIso8601String(),
                'paidBy' => $run->payer?->name,
                'paidAt' => $run->paid_at?->toDateString(),
                'paymentMethod' => $run->payment_method,
                'note' => $run->note,
            ],
            'payslips' => $run->payslips()->orderBy('employee_number')->get()->map(fn (Payslip $payslip) => [
                'id' => $payslip->id,
                'number' => $payslip->employee_number,
                'name' => $payslip->employee_name,
                'branch' => $names['branches'][$payslip->branch_id] ?? null,
                'department' => $names['departments'][$payslip->department_id] ?? null,
                'earningsMinor' => $payslip->earnings_minor,
                'incomeTaxMinor' => $payslip->income_tax_minor,
                'socialSecurityMinor' => $payslip->social_security_employee_minor,
                'otherDeductionsMinor' => $payslip->other_deductions_minor,
                'netMinor' => $payslip->net_minor,
            ]),
            'can' => [
                'prepare' => $run->isDraft() && $this->can('payroll.runs.prepare'),
                'approve' => $run->isDraft() && $this->can('payroll.runs.approve'),
                'approveSelf' => $run->prepared_by === $user->id,
                'pay' => $run->status === PayrollRun::APPROVED && $this->can('payroll.runs.pay'),
            ],
            'paymentMethods' => PayrollRun::PAYMENT_METHODS,
        ]);
    }

    public function recalculate(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->recalculate($run, $this->user($request));

        return back()->with('success', 'Recalculated with the pay and rules in force now.');
    }

    public function approve(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->approve($run, $this->user($request));

        return back()->with('success', "Payroll {$run->number} approved. It can no longer change.");
    }

    public function pay(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in(PayrollRun::PAYMENT_METHODS)],
            'paid_on' => ['required', 'date'],
        ]);

        $runs->markPaid($run, $this->user($request), $validated['method'], CarbonImmutable::parse($validated['paid_on']));

        return back()->with('success', "Payroll {$run->number} recorded as paid.");
    }

    public function destroy(PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->discard($run);

        return to_route('runs.index')->with('success', "Draft payroll {$run->number} discarded.");
    }

    public function payslip(PayrollRun $run, Payslip $payslip, Structure $structure): Response
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        $names = $structure->names();

        return Inertia::render('runs/payslip', [
            'currency' => Workspace::current()?->currency,
            'organization' => Workspace::current()?->display_name,
            'run' => $this->summary($run) + ['payDate' => $run->pay_date->toDateString()],
            'payslip' => [
                'number' => $payslip->employee_number,
                'name' => $payslip->employee_name,
                'jobTitle' => $payslip->job_title,
                'branch' => $names['branches'][$payslip->branch_id] ?? null,
                'department' => $names['departments'][$payslip->department_id] ?? null,
                'daysPaid' => $payslip->days_paid,
                'hoursPaid' => $payslip->hours_paid,
                'earningsMinor' => $payslip->earnings_minor,
                'taxableMinor' => $payslip->taxable_minor,
                'netMinor' => $payslip->net_minor,
                'lines' => $payslip->lines,
            ],
        ]);
    }

    /**
     * @return array{id: string, number: string, period: string, status: string, headcount: int, netMinor: int}
     */
    private function summary(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'number' => $run->number,
            'period' => $run->label(),
            'status' => $run->status,
            'headcount' => $run->headcount,
            'netMinor' => $run->net_minor,
        ];
    }

    private function can(string $permission): bool
    {
        return $this->access->get()?->allowsIn($permission) === true;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
