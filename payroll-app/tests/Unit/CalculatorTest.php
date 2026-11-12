<?php

use App\Models\StatutoryRule;
use App\Payroll\Calculator;
use App\Payroll\Presets;

function southSudanRule(bool $deductible = false): StatutoryRule
{
    $preset = Presets::all()['SS'];

    return (new StatutoryRule)->forceFill([
        'income_tax_bands' => $preset['income_tax_bands'],
        'social_security_employee_bp' => $preset['social_security_employee_bp'],
        'social_security_employer_bp' => $preset['social_security_employer_bp'],
        'social_security_cap_minor' => null,
        'social_security_deductible' => $deductible,
    ]);
}

it('taxes each slice of pay at its own band', function () {
    $bands = Presets::all()['SS']['income_tax_bands'];

    expect(Calculator::incomeTax(0, $bands))->toBe(0)
        ->and(Calculator::incomeTax(2_000_000, $bands))->toBe(0)
        // 5% of the 10 000 above 20 000.
        ->and(Calculator::incomeTax(3_000_000, $bands))->toBe(50_000)
        // 0 + 1 000 + 1 700 + 4 950 + 2 000 SSP.
        ->and(Calculator::incomeTax(10_000_000, $bands))->toBe(965_000);
});

it('works out a payslip: allowances, tax, social security and net', function () {
    $figures = (new Calculator)->calculate(10_000_000, [
        ['kind' => 'earning', 'name' => 'Transport', 'amount_minor' => 500_000, 'taxable' => false],
        ['kind' => 'deduction', 'name' => 'Staff loan', 'amount_minor' => 200_000, 'taxable' => false],
    ], southSudanRule());

    expect($figures)
        ->earnings_minor->toBe(10_500_000)
        ->taxable_minor->toBe(10_000_000)
        ->income_tax_minor->toBe(965_000)
        ->social_security_employee_minor->toBe(800_000)
        ->social_security_employer_minor->toBe(1_700_000)
        ->other_deductions_minor->toBe(200_000)
        ->net_minor->toBe(10_500_000 - 965_000 - 800_000 - 200_000);

    expect(array_column($figures['lines'], 'name'))->toBe(['Basic pay', 'Transport', 'Staff loan', 'Income tax', 'Social security', 'Social security (employer)']);
});

it('takes employee social security off taxable pay when the rules say so, and caps it', function () {
    $rule = southSudanRule(deductible: true);
    $figures = (new Calculator)->calculate(10_000_000, [], $rule);
    expect($figures['taxable_minor'])->toBe(9_200_000);

    $rule->social_security_cap_minor = 5_000_000;
    $capped = (new Calculator)->calculate(10_000_000, [], $rule);
    expect($capped['social_security_employee_minor'])->toBe(400_000)
        ->and($capped['social_security_employer_minor'])->toBe(850_000);
});

it('pays gross with no rules set', function () {
    expect((new Calculator)->calculate(1_000, [], null))
        ->income_tax_minor->toBe(0)
        ->net_minor->toBe(1_000);
});
