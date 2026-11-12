<?php

use App\Models\Employee;
use App\Platform\SetUpWorkspace;
use Miles\PlatformClient\Api\Organization;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
| Payroll (ADR-0009)
*/

const PAYROLL_ALL = [
    'finance.payroll.employees.view', 'finance.payroll.employees.manage', 'finance.payroll.pay.view', 'finance.payroll.pay.manage',
    'finance.payroll.settings', 'finance.payroll.runs.prepare', 'finance.payroll.runs.approve', 'finance.payroll.runs.pay',
];

/**
 * Payroll set up for the organization, with the South Sudan preset rules
 * from 1 January 2026.
 */
function setUpPayrollWithRules(string $slug = 'acme'): void
{
    $organization = Organization::fromArray(test()->platform()->organization($slug));
    test()->asTenant($slug, fn () => app(SetUpWorkspace::class)->provision($organization));

    test()->post(test()->workspaceUrl($slug, 'payroll/settings/rules'), [
        'name' => 'South Sudan',
        'effective_from' => '2026-01-01',
        'bands' => [
            ['up_to' => '20000', 'rate' => '0'],
            ['up_to' => '40000', 'rate' => '5'],
            ['up_to' => '57000', 'rate' => '10'],
            ['up_to' => '90000', 'rate' => '15'],
            ['up_to' => null, 'rate' => '20'],
        ],
        'employee_rate' => '8',
        'employer_rate' => '17',
    ])->assertSessionHasNoErrors();
}

/**
 * @param  array<string, mixed>  $details
 */
function addEmployee(string $first, string $hiredOn = '2025-01-01', ?string $salary = '100000', array $details = []): Employee
{
    test()->post(test()->workspaceUrl('acme', 'payroll/employees'), [
        'first_name' => $first, 'last_name' => 'Deng', 'hired_on' => $hiredOn, 'pay_basis' => 'monthly', ...$details,
    ])->assertSessionHasNoErrors();

    $employee = test()->asTenant('acme', fn () => Employee::query()->where('first_name', $first)->sole());

    if ($salary !== null) {
        test()->post(test()->workspaceUrl('acme', "payroll/employees/{$employee->id}/pay"), ['effective_from' => $hiredOn, 'amount' => $salary])->assertSessionHasNoErrors();
    }

    return $employee;
}

/**
 * An .xlsx download read back as CSV lines (numbers as PHP prints them:
 * 1000.0 → "1000", 6.5 → "6.5"), so tests can look for rows.
 */
function xlsxText(string $binary): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $binary);
    $reader = new Reader;
    $reader->open($path);
    $out = fopen('php://memory', 'w+');
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            fputcsv($out, array_map(fn ($value) => match (true) {
                $value === null => '',
                is_float($value) && floor($value) === $value => (string) (int) $value,
                default => (string) $value,
            }, $row->toArray()), escape: '');
        }
    }
    $reader->close();
    unlink($path);
    rewind($out);

    return stream_get_contents($out) ?: '';
}
