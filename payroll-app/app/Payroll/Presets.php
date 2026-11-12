<?php

namespace App\Payroll;

/**
 * Starting statutory rules an organization can load and then correct.
 * Loaded as ordinary data (StatutoryRule): nothing here is applied unless
 * someone saves it, and the page says it must be checked first.
 */
final class Presets
{
    /**
     * @return array<string, array{name: string, currency: string, source: string, income_tax_bands: list<array{up_to_minor: int|null, rate_bp: int}>, social_security_employee_bp: int, social_security_employer_bp: int, social_security_cap_minor: int|null, social_security_deductible: bool}>
     */
    public static function all(): array
    {
        return [
            // Monthly bands of the Financial Act 2023/2024, kept unchanged
            // by the Financial Act 2024/2025; pension 8% employee, 17%
            // employer. To be confirmed locally before the first live run
            // (ADR-0005/0008): the Finance Bill 2025/2026 may change them.
            'SS' => [
                'name' => 'South Sudan (check before use)',
                'currency' => 'SSP',
                'source' => 'Financial Act 2023/2024 bands (unchanged 2024/2025); NSIF pension 8% / 17%. Confirm against the current Financial Act.',
                'income_tax_bands' => [
                    ['up_to_minor' => 2_000_000, 'rate_bp' => 0],
                    ['up_to_minor' => 4_000_000, 'rate_bp' => 500],
                    ['up_to_minor' => 5_700_000, 'rate_bp' => 1000],
                    ['up_to_minor' => 9_000_000, 'rate_bp' => 1500],
                    ['up_to_minor' => null, 'rate_bp' => 2000],
                ],
                'social_security_employee_bp' => 800,
                'social_security_employer_bp' => 1700,
                'social_security_cap_minor' => null,
                'social_security_deductible' => false,
            ],
        ];
    }
}
