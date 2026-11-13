<?php

use App\Models\EmployeeComponent;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\TimesheetEntry;
use App\Payroll\Runs;
use App\Support\XlsxExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-03-20 09:00');
    $this->officer = $this->platformUser(['name' => 'Officer']);
    $this->approver = $this->platformUser(['name' => 'Approver']);
    $this->actingAsMember('acme', PAYROLL_ALL, user: $this->officer);
    setUpPayrollWithRules();
});

function prepareRun(string $month = '2026-03')
{
    return test()->post(test()->workspaceUrl('acme', 'runs'), ['month' => $month, 'pay_date' => '2026-03-28']);
}

function onlyRun(): PayrollRun
{
    return test()->asTenant('acme', fn () => PayrollRun::query()->sole());
}

/**
 * @return list<object>
 */
function outboxRows(): array
{
    return DB::table('platform_outbox')->orderBy('created_at')->get()->all();
}

it('prepares a month: payslips for everyone with pay, totals that add up', function () {
    addEmployee('Akol');
    addEmployee('Nyandeng', salary: '50000');
    addEmployee('Garang', salary: null); // no pay set yet: left out

    prepareRun()->assertSessionHasNoErrors();

    $run = onlyRun();
    expect($run)
        ->number->toBe('PR-00001')
        ->status->toBe(PayrollRun::DRAFT)
        ->headcount->toBe(2)
        ->earnings_minor->toBe(15_000_000)
        // 9 650 on 100 000; on 50 000: 1 000 + 1 000.
        ->income_tax_minor->toBe(965_000 + 200_000)
        ->social_security_employee_minor->toBe(1_200_000)
        ->social_security_employer_minor->toBe(2_550_000);
    expect($run->net_minor)->toBe($run->earnings_minor - $run->income_tax_minor - $run->social_security_employee_minor - $run->other_deductions_minor);

    $this->asTenant('acme', fn () => expect((int) Payslip::query()->sum('net_minor'))->toBe($run->net_minor));

    prepareRun()->assertSessionHasErrors(['month' => 'There is already a payroll for March 2026.']);
});

it('pays joiners and leavers for the days they worked', function () {
    addEmployee('Akol', hiredOn: '2026-03-16', salary: '31000'); // 16 of 31 days

    prepareRun()->assertSessionHasNoErrors();

    $payslip = $this->asTenant('acme', fn () => Payslip::query()->sole());
    expect($payslip->basic_minor)->toBe(1_600_000)
        ->and((float) $payslip->days_paid)->toBe(16.0);
});

it('adds allowances and deductions, and refuses deductions above the pay', function () {
    $akol = addEmployee('Akol', salary: '1000');
    $this->post($this->workspaceUrl('acme', 'settings/components'), ['name' => 'Housing', 'kind' => 'earning', 'calculation' => 'percent', 'taxable' => '1'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'settings/components'), ['name' => 'Staff loan', 'kind' => 'deduction', 'calculation' => 'fixed'])->assertSessionHasNoErrors();
    [$housing, $loan] = $this->asTenant('acme', fn () => [
        PayComponent::query()->where('name', 'Housing')->sole(),
        PayComponent::query()->where('name', 'Staff loan')->sole(),
    ]);

    $this->post($this->workspaceUrl('acme', "employees/{$akol->id}/components"), ['pay_component_id' => $housing->id, 'rate' => '10', 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "employees/{$akol->id}/components"), ['pay_component_id' => $loan->id, 'amount' => '5000', 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();

    prepareRun()->assertSessionHasErrors('run');
    $this->asTenant('acme', fn () => expect(PayrollRun::query()->count())->toBe(0));

    $assigned = $this->asTenant('acme', fn () => EmployeeComponent::query()->where('pay_component_id', $loan->id)->sole());
    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}/components/{$assigned->id}"), ['ends_on' => '2026-02-28'])->assertSessionHasNoErrors();

    prepareRun()->assertSessionHasNoErrors();
    $payslip = $this->asTenant('acme', fn () => Payslip::query()->sole());
    expect($payslip->earnings_minor)->toBe(110_000)
        ->and(array_column($payslip->lines, 'name'))->toContain('Housing')->not->toContain('Staff loan');
});

it('needs someone else to approve, then locks the run and sends one summarised journal to Finance', function () {
    addEmployee('Akol');
    addEmployee('Nyandeng', salary: '50000');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertSessionHasErrors(['run' => 'Someone other than whoever prepared this payroll must approve it.']);

    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.approve', 'payroll.runs.pay'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertSessionHasNoErrors();

    $run = onlyRun();
    expect($run->status)->toBe(PayrollRun::APPROVED)->and($run->approved_by)->toBe($this->approver->getAuthIdentifier());

    [$posted] = outboxRows();
    $payload = json_decode($posted->payload, true);
    expect($posted)->audience->toBe('finance')->event_type->toBe('finance.payroll.posted')->source_transaction_id->toBe('payroll:PR-00001:posted')
        ->and($payload)->toMatchArray([
            'run_reference' => 'PR-00001',
            'currency' => 'SSP',
            'headcount' => 2,
            'earnings_minor' => 15_000_000,
            'employer_contributions_minor' => 2_550_000,
            'income_tax_minor' => 1_165_000,
            'social_security_minor' => 3_750_000,
            'payable_minor' => $run->net_minor,
        ])
        // Debits equal credits.
        ->and($payload['earnings_minor'] + $payload['employer_contributions_minor'])
        ->toBe($payload['income_tax_minor'] + $payload['social_security_minor'] + $payload['other_deductions_minor'] + $payload['payable_minor']);
    // Nobody's own pay leaves Payroll.
    expect($posted->payload)->not->toContain('Akol')->not->toContain('employee');

    // Locked: no recalculation, no discarding.
    $this->actingAsMember('acme', PAYROLL_ALL, user: $this->officer);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/recalculate"))->assertSessionHasErrors('run');
    $this->delete($this->workspaceUrl('acme', "runs/{$run->id}"))->assertSessionHasErrors('run');

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'bank', 'paid_on' => '2026-03-28'])->assertSessionHasNoErrors();
    expect(onlyRun()->status)->toBe(PayrollRun::PAID);
    $paid = outboxRows()[1];
    expect($paid->event_type)->toBe('finance.payroll.paid')
        ->and(json_decode($paid->payload, true))->toBe(['run_reference' => 'PR-00001', 'payment_reference' => 'PR-00001-P1', 'currency' => 'SSP', 'amount_minor' => $run->net_minor, 'method' => 'bank_transfer']);
});

it('recalculates a draft with pay changed since, and discards a draft', function () {
    $akol = addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();

    $this->post($this->workspaceUrl('acme', "employees/{$akol->id}/pay"), ['effective_from' => '2026-03-01', 'amount' => '120000'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/recalculate"))->assertSessionHasNoErrors();
    expect(onlyRun()->earnings_minor)->toBe(12_000_000);

    $this->delete($this->workspaceUrl('acme', "runs/{$run->id}"))->assertRedirect();
    $this->asTenant('acme', fn () => expect(PayrollRun::query()->count())->toBe(0)->and(Payslip::query()->count())->toBe(0));
    expect(outboxRows())->toBe([]);
});

it('keeps pay away from members without the pay permission', function () {
    addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();

    $this->actingAsMember('acme', ['payroll.employees.view', 'payroll.employees.manage']);
    $this->get($this->workspaceUrl('acme', 'runs'))->assertForbidden();
    $this->get($this->workspaceUrl('acme', "runs/{$run->id}"))->assertForbidden();
    $this->get($this->workspaceUrl('acme', 'employees'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('seesPay', false)->where('employees.0.payMinor', null));
});

it('shows a payslip that adds up', function () {
    addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();
    $payslip = $this->asTenant('acme', fn () => Payslip::query()->sole());

    $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips/{$payslip->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('runs/payslip')
            ->where('payslip.netMinor', 10_000_000 - 965_000 - 800_000)
            ->where('payslip.name', 'Akol Deng'));
});

it('sends a run to Finance once when an approval or a payment arrives twice', function () {
    addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $runs = app(Runs::class);
        // Two requests that both loaded the run while it was a draft.
        $first = PayrollRun::query()->sole();
        $second = PayrollRun::query()->sole();

        $runs->approve($first, $this->approver);
        expect(fn () => $runs->approve($second, $this->approver))->toThrow(ValidationException::class);

        $paying = PayrollRun::query()->sole();
        $again = PayrollRun::query()->sole();
        $runs->pay($paying, $this->approver, 'bank', CarbonImmutable::parse('2026-03-28'));
        expect(fn () => $runs->pay($again, $this->approver, 'bank', CarbonImmutable::parse('2026-03-28')))->toThrow(ValidationException::class);
    });

    expect(array_column(outboxRows(), 'event_type'))->toBe(['finance.payroll.posted', 'finance.payroll.paid']);
});

it('downloads payslips as PDF and the run as an Excel register, only for those who see pay', function () {
    addEmployee('Akol');
    addEmployee('Nyandeng', salary: '50000');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();
    $payslip = $this->asTenant('acme', fn () => Payslip::query()->orderBy('employee_number')->first());

    $one = $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips/{$payslip->id}/pdf"))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($one->getContent())->toStartWith('%PDF')
        ->and($one->headers->get('Content-Disposition'))->toContain('payslip-pr-00001-');

    $all = $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips.pdf"))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($all->getContent())->toStartWith('%PDF')
        ->and($all->headers->get('Content-Disposition'))->toContain('payslips-pr-00001-march-2026.pdf');

    $register = xlsxText($this->get($this->workspaceUrl('acme', "runs/{$run->id}?export=payslips"))
        ->assertOk()
        ->assertHeader('Content-Type', XlsxExport::CONTENT_TYPE)
        ->streamedContent());
    expect($register)
        ->toContain('"Employee no.",Name,"Job title",Branch,Department,"Days paid","Hours paid","Basic pay",Earnings')
        ->toContain('P-00001,"Akol Deng",,,,31,,100000,100000,100000,9650,8000,0,82350,17000')
        ->toContain(',Total,,,,,,150000,150000,150000,11650,12000,0,126350,25500')
        ->and(substr_count(trim($register), "\n"))->toBe(3); // heading, two people, total

    // Someone else's payslip through this run is not found.
    $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips/".Str::uuid().'/pdf'))->assertNotFound();

    $this->actingAsMember('acme', ['payroll.employees.view']);
    $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips/{$payslip->id}/pdf"))->assertForbidden();
    $this->get($this->workspaceUrl('acme', "runs/{$run->id}/payslips.pdf"))->assertForbidden();
    $this->get($this->workspaceUrl('acme', "runs/{$run->id}?export=payslips"))->assertForbidden();
});

it('keeps an approved run\'s payslips unchangeable in the database itself', function () {
    addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();
    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertSessionHasNoErrors();

    expect(fn () => DB::table('payslips')->update(['net_minor' => 1]))->toThrow(QueryException::class, 'never change');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL only');

it('leaves out people paid by the hour who worked no hours that month', function () {
    addEmployee('Akol');
    addEmployee('Ayen', details: ['pay_basis' => 'hourly'], salary: '500');

    prepareRun()->assertSessionHasNoErrors();

    expect(onlyRun()->headcount)->toBe(1);
});

it('allows one payroll per month even when two are prepared at once', function () {
    addEmployee('Akol');
    prepareRun()->assertSessionHasNoErrors();
    $run = onlyRun();

    $this->asTenant('acme', fn () => expect(fn () => PayrollRun::query()->create([
        ...$run->only(['period_start', 'period_end', 'pay_date', 'status', 'prepared_by', 'prepared_at']),
        'number' => 'PR-00099',
    ]))->toThrow(QueryException::class));
});

it('pays hours and overtime in whole numbers, rounding once', function () {
    $ayen = addEmployee('Ayen', details: ['pay_basis' => 'hourly'], salary: '333.33');
    $this->asTenant('acme', function () use ($ayen) {
        foreach ([['2026-03-02', '7.25', 'regular'], ['2026-03-03', '8.00', 'regular'], ['2026-03-04', '2.50', 'overtime']] as [$date, $hours, $kind]) {
            TimesheetEntry::query()->create([
                'employee_id' => $ayen->id,
                'work_date' => $date,
                'hours' => $hours,
                'kind' => $kind,
                'source_app' => 'restaurant',
                'source_reference' => "timesheet:{$date}",
            ]);
        }
    });

    prepareRun()->assertSessionHasNoErrors();

    $payslip = $this->asTenant('acme', fn () => Payslip::query()->sole());
    // 15.25 h x 333.33 = 5 083.2825; 2.5 h x 333.33 x 3/2 = 1 249.9875.
    expect($payslip->basic_minor)->toBe(508_328)
        ->and(collect($payslip->lines)->firstWhere('name', 'Overtime')['amount_minor'])->toBe(124_999)
        ->and((float) $payslip->hours_paid)->toBe(17.75);
});
