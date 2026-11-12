<?php

namespace App\Payroll;

use App\Models\StatutoryRule;

/**
 * One person's pay for one period, in integer minor units. No database:
 * the caller works out basic pay (pro-rated, or hours at the rate) and the
 * components, and gets every figure on the payslip back.
 *
 *   earnings      = basic + earning components
 *   taxable       = basic + taxable earnings (- employee social security, if deductible)
 *   income tax    = the bands, each rate on the slice of taxable pay inside it
 *   social sec.   = basic (up to the cap) x employee rate, and x employer rate
 *   net           = earnings - income tax - employee social security - deductions
 *
 * Rounding is half up, once per figure, so payslip totals equal run totals.
 */
final class Calculator
{
    /**
     * @param  list<array{kind: 'earning'|'deduction', name: string, amount_minor: int, taxable: bool}>  $components
     * @return array{
     *     basic_minor: int, earnings_minor: int, taxable_minor: int, income_tax_minor: int,
     *     social_security_employee_minor: int, social_security_employer_minor: int,
     *     other_deductions_minor: int, net_minor: int,
     *     lines: list<array{kind: string, name: string, amount_minor: int}>
     * }
     */
    public function calculate(int $basic, array $components, ?StatutoryRule $rule, string $basicLabel = 'Basic pay'): array
    {
        $lines = [['kind' => 'earning', 'name' => $basicLabel, 'amount_minor' => $basic]];
        $earnings = $basic;
        $taxableEarnings = $basic;
        $deductions = 0;

        foreach ($components as $component) {
            if ($component['amount_minor'] <= 0) {
                continue;
            }
            $lines[] = ['kind' => $component['kind'], 'name' => $component['name'], 'amount_minor' => $component['amount_minor']];
            if ($component['kind'] === 'earning') {
                $earnings += $component['amount_minor'];
                if ($component['taxable']) {
                    $taxableEarnings += $component['amount_minor'];
                }
            } else {
                $deductions += $component['amount_minor'];
            }
        }

        $base = $rule?->social_security_cap_minor !== null ? min($basic, $rule->social_security_cap_minor) : $basic;
        $employeeSocial = $rule === null ? 0 : self::percent($base, $rule->social_security_employee_bp);
        $employerSocial = $rule === null ? 0 : self::percent($base, $rule->social_security_employer_bp);

        $taxable = max(0, $taxableEarnings - ($rule?->social_security_deductible ? $employeeSocial : 0));
        $tax = $rule === null ? 0 : self::incomeTax($taxable, $rule->income_tax_bands);

        if ($tax > 0) {
            $lines[] = ['kind' => 'tax', 'name' => 'Income tax', 'amount_minor' => $tax];
        }
        if ($employeeSocial > 0) {
            $lines[] = ['kind' => 'social_security', 'name' => 'Social security', 'amount_minor' => $employeeSocial];
        }
        if ($employerSocial > 0) {
            $lines[] = ['kind' => 'employer', 'name' => 'Social security (employer)', 'amount_minor' => $employerSocial];
        }

        return [
            'basic_minor' => $basic,
            'earnings_minor' => $earnings,
            'taxable_minor' => $taxable,
            'income_tax_minor' => $tax,
            'social_security_employee_minor' => $employeeSocial,
            'social_security_employer_minor' => $employerSocial,
            'other_deductions_minor' => $deductions,
            'net_minor' => $earnings - $tax - $employeeSocial - $deductions,
            'lines' => $lines,
        ];
    }

    /**
     * Progressive: each band's rate on the slice of pay inside it.
     *
     * @param  list<array{up_to_minor: int|null, rate_bp: int}>  $bands  ascending; the last one open (null)
     */
    public static function incomeTax(int $taxable, array $bands): int
    {
        $lower = 0;
        $scaled = 0; // minor units x 10 000

        foreach ($bands as $band) {
            $upper = $band['up_to_minor'];
            $slice = min($taxable, $upper ?? PHP_INT_MAX) - $lower;
            if ($slice > 0) {
                $scaled += $slice * $band['rate_bp'];
            }
            if ($upper === null || $taxable <= $upper) {
                break;
            }
            $lower = $upper;
        }

        return intdiv($scaled + 5000, 10000);
    }

    /** An amount times a rate in basis points, half up. */
    public static function percent(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5000, 10000);
    }
}
