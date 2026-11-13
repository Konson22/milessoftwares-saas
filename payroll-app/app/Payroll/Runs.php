<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\EmployeeComponent;
use App\Models\PayComponent;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipPayment;
use App\Models\RunAdjustment;
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
 *             has basic pay set; recalculated on demand until approved
 *   review    optional: someone other than the preparer records that they
 *             checked it; recalculating clears it
 *   approve   by someone other than whoever last prepared it; the run and
 *             its payslips never change again, and Finance gets one
 *             summarised journal (finance.payroll.posted)
 *   pay       net pay that went out, for all the unpaid payslips or some of
 *             them; one finance.payroll.paid per batch
 *   reverse   an approved run nobody has been paid from yet: Finance
 *             reverses the journal (finance.payroll.reversed) and the month
 *             can be prepared again
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
        private readonly AuditTrail $audit,
    ) {}

    public function prepare(CarbonImmutable $month, CarbonImmutable $payDate, User $preparer, ?string $note = null): PayrollRun
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();

        if (PayrollRun::query()->whereDate('period_start', $start->toDateString())->where('status', '!=', PayrollRun::REVERSED)->exists()) {
            throw ValidationException::withMessages(['month' => "There is already a payroll for {$start->format('F Y')}."]);
        }

        return DB::transaction(function () use ($start, $end, $payDate, $preparer, $note) {
            $run = new PayrollRun([
                'number' => $this->numbers->next('payroll_run', 'PR'),
                'period_start' => $start,
                'period_end' => $end,
                'pay_date' => $payDate,
                'status' => PayrollRun::DRAFT,
                // The run stays in the currency it was prepared in.
                'currency' => $this->currency(),
                'prepared_by' => $preparer->id,
                'prepared_at' => now(),
                'note' => $note,
            ]);
            $run->save();

            $this->calculate($run);
            $this->audit->record('run.prepared', $run, $preparer, ['period' => $run->label(), 'headcount' => $run->headcount, 'net_minor' => $run->net_minor]);

            return $run;
        });
    }

    public function recalculate(PayrollRun $run, User $preparer): void
    {
        DB::transaction(function () use ($run, $preparer) {
            $this->lock($run);
            $this->ensureDraft($run);
            $this->rebuild($run, $preparer);
            $this->audit->record('run.recalculated', $run, $preparer, ['headcount' => $run->headcount, 'net_minor' => $run->net_minor]);
        });
    }

    /**
     * The payslips worked out again. Whoever changed the figures becomes the
     * preparer (so cannot approve them), and any review no longer stands.
     */
    private function rebuild(PayrollRun $run, User $preparer): void
    {
        // Back to draft first: the database refuses payslip changes otherwise.
        $run->forceFill(['status' => PayrollRun::DRAFT, 'reviewed_by' => null, 'reviewed_at' => null, 'prepared_by' => $preparer->id, 'prepared_at' => now()])->save();
        $run->payslips()->delete();
        $this->calculate($run);
    }

    public function discard(PayrollRun $run, User $user): void
    {
        DB::transaction(function () use ($run, $user) {
            $this->lock($run);
            $this->ensureDraft($run);
            $run->forceFill(['status' => PayrollRun::DRAFT])->save();
            $run->payslips()->delete();
            $this->audit->record('run.discarded', $run, $user, ['period' => $run->label()]);
            $run->delete();
        });
    }

    /**
     * A one-off earning or deduction on one person's payslip in this draft;
     * the run is recalculated with it (and any review cleared).
     *
     * @param  'earning'|'deduction'  $kind
     */
    public function addAdjustment(PayrollRun $run, Employee $employee, string $kind, string $name, int $amountMinor, bool $taxable, User $user): void
    {
        DB::transaction(function () use ($run, $employee, $kind, $name, $amountMinor, $taxable, $user) {
            $this->lock($run);
            $this->ensureDraft($run);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
            }
            if (! $run->payslips()->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Only people on this payroll can have an adjustment: set their pay, then recalculate.']);
            }

            RunAdjustment::query()->create([
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'kind' => $kind,
                'name' => $name,
                'amount_minor' => $amountMinor,
                // A deduction never changes taxable pay.
                'taxable' => $kind === RunAdjustment::EARNING && $taxable,
                'created_by' => $user->id,
            ]);
            $this->audit->record('run.adjustment_added', $run, $user, ['employee' => "{$employee->name()} ({$employee->employee_number})", 'component' => $name, 'kind' => $kind, 'amount_minor' => $amountMinor]);
            $this->rebuild($run, $user);
        });
    }

    public function removeAdjustment(PayrollRun $run, RunAdjustment $adjustment, User $user): void
    {
        DB::transaction(function () use ($run, $adjustment, $user) {
            $this->lock($run);
            $this->ensureDraft($run);
            abort_unless($adjustment->payroll_run_id === $run->id, 404);

            $adjustment->delete();
            $this->audit->record('run.adjustment_removed', $run, $user, ['employee' => "{$adjustment->employee->name()} ({$adjustment->employee->employee_number})", 'component' => $adjustment->name, 'kind' => $adjustment->kind, 'amount_minor' => $adjustment->amount_minor]);
            $this->rebuild($run, $user);
        });
    }

    public function review(PayrollRun $run, User $reviewer): void
    {
        DB::transaction(function () use ($run, $reviewer) {
            $this->lock($run);
            if ($run->status !== PayrollRun::DRAFT) {
                throw ValidationException::withMessages(['run' => 'Only a draft payroll can be marked as reviewed.']);
            }
            if ($run->prepared_by === $reviewer->id) {
                throw ValidationException::withMessages(['run' => 'Someone other than whoever prepared this payroll must review it.']);
            }
            $this->ensureSomeonePaid($run);

            $run->forceFill(['status' => PayrollRun::REVIEWED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
            $this->audit->record('run.reviewed', $run, $reviewer);
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
            $this->ensureSomeonePaid($run);

            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            $this->audit->record('run.approved', $run, $approver, ['net_minor' => $run->net_minor]);

            $this->outbox->record('finance', 'finance.payroll.posted', 1, "payroll:{$run->number}:posted", [
                'run_reference' => $run->number,
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'currency' => $this->currencyOf($run),
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
     * Records net pay that went out: for every payslip not yet paid, or only
     * those listed. One batch, one finance.payroll.paid.
     *
     * @param  'bank'|'cash'|'mobile_money'  $method
     * @param  list<string>|null  $payslipIds  null: every unpaid payslip
     */
    public function pay(PayrollRun $run, User $payer, string $method, CarbonImmutable $paidOn, ?string $reference = null, ?array $payslipIds = null): PayrollPayment
    {
        return DB::transaction(function () use ($run, $payer, $method, $paidOn, $reference, $payslipIds) {
            // Locked first: each payslip's net pay must be recorded once.
            $this->lock($run);
            if (! $run->isPayable()) {
                throw ValidationException::withMessages(['run' => 'Only an approved payroll can be paid.']);
            }

            $unpaid = $run->payslips()
                ->whereDoesntHave('payment')
                ->when($payslipIds !== null, fn ($query) => $query->whereIn('id', $payslipIds))
                ->orderBy('employee_number')
                ->get(['id', 'net_minor']);
            if ($unpaid->isEmpty()) {
                throw ValidationException::withMessages(['payslips' => 'Choose at least one payslip that has not been paid.']);
            }
            if ($payslipIds !== null && $unpaid->count() !== count(array_unique($payslipIds))) {
                throw ValidationException::withMessages(['payslips' => 'Some of the chosen payslips are already paid or are not on this payroll.']);
            }

            $amount = (int) $unpaid->sum('net_minor');
            $payment = PayrollPayment::query()->create([
                'payroll_run_id' => $run->id,
                'number' => "{$run->number}-P".($run->payments()->count() + 1),
                'method' => $method,
                'paid_on' => $paidOn,
                'reference' => $reference,
                'amount_minor' => $amount,
                'headcount' => $unpaid->count(),
                'recorded_by' => $payer->id,
            ]);
            foreach ($unpaid as $payslip) {
                PayslipPayment::query()->create(['payroll_payment_id' => $payment->id, 'payslip_id' => $payslip->id, 'amount_minor' => $payslip->net_minor]);
            }

            $everyone = ! $run->payslips()->whereDoesntHave('payment')->exists();
            $run->forceFill([
                'paid_minor' => $run->paid_minor + $amount,
                'status' => $everyone ? PayrollRun::PAID : PayrollRun::PART_PAID,
                // Fully paid: when, how (the last batch) and who finished it.
                ...($everyone ? ['paid_by' => $payer->id, 'paid_at' => $paidOn, 'payment_method' => $method] : []),
            ])->save();
            $this->audit->record('run.paid', $run, $payer, ['payment' => $payment->number, 'headcount' => $payment->headcount, 'amount_minor' => $amount, 'method' => $method, 'reference' => $reference]);

            $this->outbox->record('finance', 'finance.payroll.paid', 1, "payroll:{$payment->number}:paid", [
                'run_reference' => $run->number,
                'payment_reference' => $payment->number,
                'currency' => $this->currencyOf($run),
                'amount_minor' => $amount,
                // Finance's payment methods (finance.payment.received).
                'method' => $method === 'bank' ? 'bank_transfer' : $method,
            ], $paidOn);

            return $payment;
        });
    }

    /**
     * Takes back an approved run before any of its pay went out. Finance
     * reverses the journal; the payslips stay, marked reversed, and the
     * month can be prepared again.
     */
    public function reverse(PayrollRun $run, User $user, string $reason): void
    {
        DB::transaction(function () use ($run, $user, $reason) {
            $this->lock($run);
            if ($run->status !== PayrollRun::APPROVED || $run->paid_minor > 0) {
                throw ValidationException::withMessages(['run' => 'Only an approved payroll that nobody has been paid from can be reversed.']);
            }

            $on = CarbonImmutable::today();
            $run->forceFill(['status' => PayrollRun::REVERSED, 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
            $this->audit->record('run.reversed', $run, $user, ['reason' => $reason]);

            // Finance posted nothing for an all-zero run: nothing to take back.
            if ($run->earnings_minor + $run->social_security_employer_minor > 0) {
                $this->outbox->record('finance', 'finance.payroll.reversed', 1, "payroll:{$run->number}:reversed", [
                    'run_reference' => $run->number,
                    'reversed_on' => $on->toDateString(),
                    'reason' => $reason,
                ], $on);
            }
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
        $adjustments = RunAdjustment::query()->where('payroll_run_id', $run->id)->orderBy('created_at')->orderBy('id')->get()->groupBy('employee_id');

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
            // This run's one-off amounts, as entered.
            foreach ($adjustments->get($employee->id, []) as $adjustment) {
                /** @var RunAdjustment $adjustment */
                $components[] = ['kind' => $adjustment->kind === RunAdjustment::DEDUCTION ? 'deduction' : 'earning', 'name' => $adjustment->name, 'amount_minor' => $adjustment->amount_minor, 'taxable' => $adjustment->taxable];
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
            throw ValidationException::withMessages(['run' => $run->status === PayrollRun::REVERSED
                ? 'This payroll was reversed and can no longer change.'
                : 'This payroll is approved and can no longer change.']);
        }
    }

    private function ensureSomeonePaid(PayrollRun $run): void
    {
        if ($run->headcount === 0) {
            throw ValidationException::withMessages(['run' => 'Nobody is paid on this payroll: set basic pay first, then recalculate.']);
        }
    }

    private function currency(): string
    {
        return Workspace::current()->currency ?? 'USD';
    }

    /** The currency the run was prepared in (runs from before it was kept: today's). */
    private function currencyOf(PayrollRun $run): string
    {
        return $run->currency ?? $this->currency();
    }
}
