<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\EmployeeComponent;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryRule;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Payroll runs, one calendar month each:
 *
 *   prepare   a draft with a payslip for everyone employed in the month who
 *             has basic pay set; recalculated on demand while a draft
 *   approve   by someone other than whoever last prepared it; the run and
 *             its payslips never change again, and Finance gets one
 *             summarised journal (finance.payroll.posted)
 *   pay       records that the net pay went out (finance.payroll.paid)
 *
 * Events to Finance carry run totals only, never one person's pay; the
 * outbox skips them when the organization does not have Finance.
 */
class Runs
{
    /** Overtime hours are paid at 3/2 (time and a half) of the hourly rate. */
    public const OVERTIME_NUMERATOR = 3;

    public const OVERTIME_DENOMINATOR = 2;

    public function __construct(
        private readonly Calculator $calculator,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    public function prepare(CarbonImmutable $month, CarbonImmutable $payDate, User $preparer, ?string $note = null): PayrollRun
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();

        if (PayrollRun::query()->whereDate('period_start', $start->toDateString())->exists()) {
            throw ValidationException::withMessages(['month' => "There is already a payroll for {$start->format('F Y')}."]);
        }

        return DB::transaction(function () use ($start, $end, $payDate, $preparer, $note) {
            $run = new PayrollRun([
                'number' => $this->numbers->next('payroll_run', 'PR'),
                'period_start' => $start,
                'period_end' => $end,
                'pay_date' => $payDate,
                'status' => PayrollRun::DRAFT,
                'prepared_by' => $preparer->id,
                'prepared_at' => now(),
                'note' => $note,
            ]);
            $run->save();

            $this->calculate($run);

            return $run;
        });
    }

    public function recalculate(PayrollRun $run, User $preparer): void
    {
        DB::transaction(function () use ($run, $preparer) {
            $this->lock($run);
            $this->ensureDraft($run);
            $run->payslips()->delete();
            $run->forceFill(['prepared_by' => $preparer->id, 'prepared_at' => now()])->save();
            $this->calculate($run);
        });
    }

    public function discard(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $this->lock($run);
            $this->ensureDraft($run);
            $run->payslips()->delete();
            $run->delete();
        });
    }

    public function approve(PayrollRun $run, User $approver): void
    {
        DB::transaction(function () use ($run, $approver) {
            // Locked first: two approvals at once must not send the journal twice.
            $this->lock($run);
            $this->ensureDraft($run);

            if ($run->prepared_by === $approver->id) {
                throw ValidationException::withMessages(['run' => 'Someone other than whoever prepared this payroll must approve it.']);
            }
            if ($run->headcount === 0) {
                throw ValidationException::withMessages(['run' => 'Nobody is paid on this payroll: set basic pay first, then recalculate.']);
            }

            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now()])->save();

            $this->outbox->record('finance', 'finance.payroll.posted', 1, "payroll:{$run->number}:posted", [
                'run_reference' => $run->number,
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'currency' => $this->currency(),
                'headcount' => $run->headcount,
                'earnings_minor' => $run->earnings_minor,
                'employer_contributions_minor' => $run->social_security_employer_minor,
                'income_tax_minor' => $run->income_tax_minor,
                'social_security_minor' => $run->social_security_employee_minor + $run->social_security_employer_minor,
                'other_deductions_minor' => $run->other_deductions_minor,
                'payable_minor' => $run->net_minor,
            ], $run->period_end);
        });
    }

    /**
     * @param  'bank'|'cash'|'mobile_money'  $method
     */
    public function markPaid(PayrollRun $run, User $payer, string $method, CarbonImmutable $paidOn): void
    {
        DB::transaction(function () use ($run, $payer, $method, $paidOn) {
            // Locked first: the net pay must be recorded as paid once.
            $this->lock($run);
            if ($run->status !== PayrollRun::APPROVED) {
                throw ValidationException::withMessages(['run' => 'Only an approved payroll can be marked as paid.']);
            }

            $run->forceFill(['status' => PayrollRun::PAID, 'paid_by' => $payer->id, 'paid_at' => $paidOn, 'payment_method' => $method])->save();

            $this->outbox->record('finance', 'finance.payroll.paid', 1, "payroll:{$run->number}:paid", [
                'run_reference' => $run->number,
                'currency' => $this->currency(),
                'amount_minor' => $run->net_minor,
                // Finance's payment methods (finance.payment.received).
                'method' => $method === 'bank' ? 'bank_transfer' : $method,
            ], $paidOn);
        });
    }

    private function calculate(PayrollRun $run): void
    {
        $start = $run->period_start;
        $end = $run->period_end;
        $rule = StatutoryRule::inForceOn($end);
        $daysInPeriod = (int) $start->diffInDays($end) + 1;
        $totals = ['headcount' => 0, 'earnings_minor' => 0, 'income_tax_minor' => 0, 'social_security_employee_minor' => 0, 'social_security_employer_minor' => 0, 'other_deductions_minor' => 0, 'net_minor' => 0];
        $refused = [];

        $employees = Employee::query()
            ->whereDate('hired_on', '<=', $end->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('left_on')->orWhereDate('left_on', '>=', $start->toDateString()))
            ->with(['components' => fn ($query) => $query->with('component')])
            ->orderBy('employee_number')
            ->get();

        foreach ($employees as $employee) {
            $from = $employee->hired_on->max($start);
            $to = $employee->left_on === null ? $end : $employee->left_on->min($end);
            $pay = $employee->payOn($to);
            if ($pay === null) {
                continue;
            }

            $days = null;
            $hours = null;
            $overtime = 0;
            if ($employee->pay_basis === Employee::HOURLY) {
                $entries = TimesheetEntry::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', '>=', $from->toDateString())
                    ->whereDate('work_date', '<=', $to->toDateString())
                    ->get();
                // Hours in hundredths (7.25 h is 725): whole numbers throughout.
                $ordinary = (int) $entries->where('kind', '!=', 'overtime')->sum(fn (TimesheetEntry $entry) => Money::hundredths($entry->hours));
                $extra = (int) $entries->where('kind', 'overtime')->sum(fn (TimesheetEntry $entry) => Money::hundredths($entry->hours));
                // Paid by the hour with no hours this month: not on this payroll.
                if ($ordinary + $extra <= 0) {
                    continue;
                }
                $hours = Money::fromHundredths($ordinary + $extra);
                $basic = Money::share($pay->amount_minor, $ordinary, 100);
                $overtime = Money::share($pay->amount_minor, $extra * self::OVERTIME_NUMERATOR, 100 * self::OVERTIME_DENOMINATOR);
                // Fixed allowances are paid in full.
                [$worked, $ofDays] = [1, 1];
            } else {
                $days = (int) $from->diffInDays($to) + 1;
                [$worked, $ofDays] = [$days, $daysInPeriod];
                $basic = Money::share($pay->amount_minor, $worked, $ofDays);
            }

            $components = $overtime > 0 ? [['kind' => 'earning', 'name' => 'Overtime', 'amount_minor' => $overtime, 'taxable' => true]] : [];
            foreach ($employee->components as $assigned) {
                /** @var EmployeeComponent $assigned */
                if (! $assigned->component->active || ! $assigned->appliesBetween($from, $to)) {
                    continue;
                }
                $kind = $assigned->component->kind === PayComponent::EARNING ? 'earning' : 'deduction';
                $amount = $assigned->component->calculation === PayComponent::PERCENT
                    ? Calculator::percent($basic, (int) $assigned->rate_bp)
                    // A fixed allowance follows the days worked; a fixed deduction does not.
                    : ($kind === 'earning' ? Money::share((int) $assigned->amount_minor, $worked, $ofDays) : (int) $assigned->amount_minor);
                $components[] = ['kind' => $kind, 'name' => $assigned->component->name, 'amount_minor' => $amount, 'taxable' => $assigned->component->taxable];
            }

            $label = $employee->pay_basis === Employee::HOURLY ? 'Hours worked' : 'Basic salary';
            $figures = $this->calculator->calculate($basic, $components, $rule, $label);
            if ($figures['net_minor'] < 0) {
                $refused[] = "{$employee->name()} ({$employee->employee_number})";

                continue;
            }

            (new Payslip([
                ...$figures,
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'employee_name' => $employee->name(),
                'job_title' => $employee->job_title,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'days_paid' => $days,
                'hours_paid' => $hours,
            ]))->save();

            $totals['headcount']++;
            foreach (array_keys($totals) as $key) {
                if ($key !== 'headcount') {
                    $totals[$key] += $figures[$key];
                }
            }
        }

        if ($refused !== []) {
            throw ValidationException::withMessages(['run' => 'Deductions are more than the pay of: '.implode(', ', $refused).'. Change their deductions, then prepare again.']);
        }

        $run->forceFill($totals)->save();
    }

    /**
     * Re-reads the run under a row lock, so a status check and the change it
     * guards cannot interleave with another request's.
     */
    private function lock(PayrollRun $run): void
    {
        $fresh = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
        $run->setRawAttributes($fresh->getAttributes(), true);
    }

    private function ensureDraft(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => 'This payroll is approved and can no longer change.']);
        }
    }

    private function currency(): string
    {
        return Workspace::current()->currency ?? 'USD';
    }
}
