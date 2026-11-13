<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipPayment;
use App\Models\RunAdjustment;
use App\Models\Workspace;
use App\Payroll\Runs;
use App\Support\Money;
use App\Support\Structure;
use App\Support\XlsxExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function show(Request $request, PayrollRun $run, Structure $structure): Response|StreamedResponse
    {
        $names = $structure->names();

        if ($request->query('export') === 'payslips') {
            return $this->register($run, $names);
        }
        if ($request->query('export') === 'instructions') {
            return $this->instructions($run, $request->query('payment'));
        }
        $run->load(['preparer:id,name', 'reviewer:id,name', 'approver:id,name', 'payer:id,name', 'reverser:id,name']);
        $user = $this->user($request);
        $paidIn = PayslipPayment::query()
            ->whereIn('payslip_id', $run->payslips()->select('id'))
            ->with('payment:id,number')
            ->get()
            ->mapWithKeys(fn (PayslipPayment $line) => [$line->payslip_id => $line->payment->number]);

        return Inertia::render('runs/show', [
            'currency' => $run->currency ?? Workspace::current()?->currency,
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
                'paidMinor' => $run->paid_minor,
                'preparedBy' => $run->preparer->name,
                'preparedAt' => $run->prepared_at->toIso8601String(),
                'reviewedBy' => $run->reviewer?->name,
                'reviewedAt' => $run->reviewed_at?->toIso8601String(),
                'approvedBy' => $run->approver?->name,
                'approvedAt' => $run->approved_at?->toIso8601String(),
                'paidBy' => $run->payer?->name,
                'paidAt' => $run->paid_at?->toDateString(),
                'paymentMethod' => $run->payment_method,
                'reversedBy' => $run->reverser?->name,
                'reversedAt' => $run->reversed_at?->toIso8601String(),
                'reversalReason' => $run->reversal_reason,
                'note' => $run->note,
            ],
            'payments' => $run->payments()->with('recorder:id,name')->orderBy('created_at')->get()->map(fn (PayrollPayment $payment) => [
                'id' => $payment->id,
                'number' => $payment->number,
                'method' => $payment->method,
                'paidOn' => $payment->paid_on->toDateString(),
                'reference' => $payment->reference,
                'amountMinor' => $payment->amount_minor,
                'headcount' => $payment->headcount,
                'recordedBy' => $payment->recorder->name,
            ]),
            'adjustments' => RunAdjustment::query()->where('payroll_run_id', $run->id)->with(['employee', 'creator:id,name'])->orderBy('created_at')->orderBy('id')->get()->map(fn (RunAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'employee' => "{$adjustment->employee->name()} ({$adjustment->employee->employee_number})",
                'kind' => $adjustment->kind,
                'name' => $adjustment->name,
                'amountMinor' => $adjustment->amount_minor,
                'taxable' => $adjustment->taxable,
                'by' => $adjustment->creator->name,
            ]),
            'payslips' => $run->payslips()->orderBy('employee_number')->get()->map(fn (Payslip $payslip) => [
                'id' => $payslip->id,
                'employeeId' => $payslip->employee_id,
                'number' => $payslip->employee_number,
                'name' => $payslip->employee_name,
                'branch' => $names['branches'][$payslip->branch_id] ?? null,
                'department' => $names['departments'][$payslip->department_id] ?? null,
                'earningsMinor' => $payslip->earnings_minor,
                'incomeTaxMinor' => $payslip->income_tax_minor,
                'socialSecurityMinor' => $payslip->social_security_employee_minor,
                'otherDeductionsMinor' => $payslip->other_deductions_minor,
                'netMinor' => $payslip->net_minor,
                'paidIn' => $paidIn[$payslip->id] ?? null,
            ]),
            'can' => [
                'prepare' => $run->isDraft() && $this->can('payroll.runs.prepare'),
                'review' => $run->status === PayrollRun::DRAFT && $this->can('payroll.runs.review'),
                'approve' => $run->isDraft() && $this->can('payroll.runs.approve'),
                'approveSelf' => $run->prepared_by === $user->id,
                'pay' => $run->isPayable() && $this->can('payroll.runs.pay'),
                'reverse' => $run->status === PayrollRun::APPROVED && $run->paid_minor === 0 && $this->can('payroll.runs.approve'),
            ],
            'paymentMethods' => PayrollRun::PAYMENT_METHODS,
        ]);
    }

    public function recalculate(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->recalculate($run, $this->user($request));

        return back()->with('success', 'Recalculated with the pay and rules in force now.');
    }

    public function storeAdjustment(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in([RunAdjustment::EARNING, RunAdjustment::DEDUCTION])],
            'name' => ['required', 'string', 'max:100'],
            'amount' => Money::RULES,
            'taxable' => ['boolean'],
        ], [], ['employee_id' => 'person']);

        $employee = Employee::query()->whereKey($validated['employee_id'])->first();
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose someone on this payroll.']);
        }

        $runs->addAdjustment($run, $employee, $validated['kind'], $validated['name'], Money::toMinor($validated['amount']), $request->boolean('taxable'), $this->user($request));

        return back()->with('success', "{$validated['name']} added for {$employee->name()}; payroll recalculated.");
    }

    public function destroyAdjustment(Request $request, PayrollRun $run, RunAdjustment $adjustment, Runs $runs): RedirectResponse
    {
        $runs->removeAdjustment($run, $adjustment, $this->user($request));

        return back()->with('success', "{$adjustment->name} removed; payroll recalculated.");
    }

    public function review(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->review($run, $this->user($request));

        return back()->with('success', "Payroll {$run->number} marked as reviewed.");
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
            'reference' => ['nullable', 'string', 'max:100'],
            // Left out: every payslip not paid yet.
            'payslips' => ['nullable', 'array', 'min:1'],
            'payslips.*' => ['uuid'],
        ]);

        $payment = $runs->pay(
            $run,
            $this->user($request),
            $validated['method'],
            CarbonImmutable::parse($validated['paid_on']),
            $validated['reference'] ?? null,
            isset($validated['payslips']) ? array_values($validated['payslips']) : null,
        );

        return back()->with('success', "Payment {$payment->number} recorded: {$payment->headcount} ".Str::plural('payslip', $payment->headcount).'.');
    }

    public function reverse(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $runs->reverse($run, $this->user($request), $validated['reason']);

        return back()->with('success', "Payroll {$run->number} reversed. {$run->label()} can be prepared again.");
    }

    public function destroy(Request $request, PayrollRun $run, Runs $runs): RedirectResponse
    {
        $runs->discard($run, $this->user($request));

        return to_route('runs.index')->with('success', "Draft payroll {$run->number} discarded.");
    }

    public function payslip(PayrollRun $run, Payslip $payslip, Structure $structure): Response
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        return Inertia::render('runs/payslip', [
            'currency' => $run->currency ?? Workspace::current()?->currency,
            'organization' => Workspace::current()?->display_name,
            'run' => $this->summary($run) + ['payDate' => $run->pay_date->toDateString()],
            'payslip' => $this->payslipData($payslip, $structure->names()),
        ]);
    }

    /** One payslip as a PDF, to send or file. */
    public function payslipPdf(PayrollRun $run, Payslip $payslip, Structure $structure): HttpResponse
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        return $this->pdf($run, collect([$payslip]), $structure->names())
            ->download(Str::slug("payslip {$run->number} {$payslip->employee_number} {$payslip->employee_name}").'.pdf');
    }

    /** Every payslip of a run in one PDF, one page each, to print and hand out. */
    public function payslipsPdf(PayrollRun $run, Structure $structure): HttpResponse
    {
        $payslips = $run->payslips()->orderBy('employee_number')->get();
        abort_if($payslips->isEmpty(), 404);

        return $this->pdf($run, $payslips, $structure->names())
            ->download(Str::slug("payslips {$run->number} {$run->label()}").'.pdf');
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @param  array{branches: array<string, string>, departments: array<string, string>}  $names
     */
    private function pdf(PayrollRun $run, Collection $payslips, array $names): \Barryvdh\DomPDF\PDF
    {
        $workspace = Workspace::current();

        return Pdf::loadView('payroll.payslips-pdf', [
            'organization' => $workspace?->display_name,
            'currency' => $run->currency ?? $workspace?->currency,
            'run' => $run,
            'payslips' => $payslips->map(fn (Payslip $payslip) => $this->payslipData($payslip, $names)),
        ])->setPaper('a4')->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false]);
    }

    /**
     * The run's payslips as a payroll register in Excel, with a totals row.
     *
     * @param  array{branches: array<string, string>, departments: array<string, string>}  $names
     */
    private function register(PayrollRun $run, array $names): StreamedResponse
    {
        $rows = $run->payslips()->orderBy('employee_number')->get()->map(fn (Payslip $payslip) => [
            'number' => $payslip->employee_number,
            'name' => $payslip->employee_name,
            'jobTitle' => $payslip->job_title,
            'branch' => $names['branches'][$payslip->branch_id] ?? null,
            'department' => $names['departments'][$payslip->department_id] ?? null,
            'daysPaid' => $payslip->days_paid === null ? null : (float) $payslip->days_paid,
            'hoursPaid' => $payslip->hours_paid === null ? null : (float) $payslip->hours_paid,
            'basicMinor' => $payslip->basic_minor,
            'earningsMinor' => $payslip->earnings_minor,
            'taxableMinor' => $payslip->taxable_minor,
            'incomeTaxMinor' => $payslip->income_tax_minor,
            'socialSecurityEmployeeMinor' => $payslip->social_security_employee_minor,
            'otherDeductionsMinor' => $payslip->other_deductions_minor,
            'netMinor' => $payslip->net_minor,
            'socialSecurityEmployerMinor' => $payslip->social_security_employer_minor,
        ])->all();

        $total = ['name' => 'Total'];
        foreach (['basicMinor', 'earningsMinor', 'taxableMinor', 'incomeTaxMinor', 'socialSecurityEmployeeMinor', 'otherDeductionsMinor', 'netMinor', 'socialSecurityEmployerMinor'] as $key) {
            $total[$key] = array_sum(array_column($rows, $key));
        }

        return XlsxExport::download(Str::slug("payroll {$run->number} {$run->label()}"), [
            'Employee no.' => 'number',
            'Name' => 'name',
            'Job title' => 'jobTitle',
            'Branch' => 'branch',
            'Department' => 'department',
            'Days paid' => 'daysPaid',
            'Hours paid' => 'hoursPaid',
            'Basic pay' => 'basicMinor',
            'Earnings' => 'earningsMinor',
            'Taxable pay' => 'taxableMinor',
            'Income tax' => 'incomeTaxMinor',
            'Social security (employee)' => 'socialSecurityEmployeeMinor',
            'Other deductions' => 'otherDeductionsMinor',
            'Net pay' => 'netMinor',
            'Social security (employer)' => 'socialSecurityEmployerMinor',
        ], [...$rows, $total], decimals: 2);
    }

    /**
     * What to pay into whose account, as an Excel file for the bank or the
     * cashier: the payslips not paid yet, or those of one payment batch.
     */
    private function instructions(PayrollRun $run, mixed $paymentId): StreamedResponse
    {
        abort_unless($run->isPayable() || $run->status === PayrollRun::PAID, 404);

        $payment = is_string($paymentId) && Str::isUuid($paymentId)
            ? $run->payments()->whereKey($paymentId)->firstOrFail()
            : null;
        $payslips = $run->payslips()
            ->when(
                $payment === null,
                fn ($query) => $query->whereDoesntHave('payment'),
                fn ($query) => $query->whereHas('payment', fn ($line) => $line->where('payroll_payment_id', $payment?->id)),
            )
            ->with('employee')
            ->orderBy('employee_number')
            ->get();

        $methods = ['bank' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'cash' => 'Cash'];
        $rows = $payslips->map(function (Payslip $payslip) use ($methods) {
            /** @var array<string, string|null> $details */
            $details = $payslip->employee->payment_details ?? [];

            return [
                'number' => $payslip->employee_number,
                'name' => $payslip->employee_name,
                'method' => $methods[$details['method'] ?? ''] ?? 'Not set',
                'bank' => $details['bank_name'] ?? null,
                'accountName' => $details['account_name'] ?? null,
                'accountNumber' => $details['account_number'] ?? null,
                'mobileNumber' => $details['mobile_number'] ?? null,
                'netMinor' => $payslip->net_minor,
            ];
        })->all();
        $rows[] = ['name' => 'Total', 'netMinor' => array_sum(array_column($rows, 'netMinor'))];

        return XlsxExport::download(Str::slug('payment instructions '.($payment->number ?? "{$run->number} unpaid")), [
            'Employee no.' => 'number',
            'Name' => 'name',
            'Paid by' => 'method',
            'Bank' => 'bank',
            'Account name' => 'accountName',
            'Account number' => 'accountNumber',
            'Mobile number' => 'mobileNumber',
            'Net pay' => 'netMinor',
        ], $rows, decimals: 2);
    }

    /**
     * @param  array{branches: array<string, string>, departments: array<string, string>}  $names
     * @return array<string, mixed>
     */
    private function payslipData(Payslip $payslip, array $names): array
    {
        return [
            'id' => $payslip->id,
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
        ];
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
}
