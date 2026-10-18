<?php

namespace Database\Seeders;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Enums\ApplicationStatus;
use App\Modules\Applications\Models\Application;
use Illuminate\Database\Seeder;

/**
 * Announced applications for filling the public site's Applications
 * section on a developer machine. They are "planned", so the site lists
 * them as coming soon and offers no trial. Safe to run again; it never
 * touches applications that already exist.
 *
 *   php artisan db:seed --class=DemoApplicationsSeeder
 */
class DemoApplicationsSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, tagline: string, description: string, features: list<string>}> */
    private const APPLICATIONS = [
        [
            'code' => 'retail',
            'name' => 'Retail & POS',
            'tagline' => 'Sell at the counter, track every item.',
            'description' => 'Fast checkout for shops and supermarkets, with stock that updates as you sell, barcode scanning and daily till reports for every branch.',
            'features' => ['Point of sale with barcode scanning', 'Stock that updates as you sell', 'Daily till and sales reports', 'Prices and promotions per branch'],
        ],
        [
            'code' => 'inventory',
            'name' => 'Inventory',
            'tagline' => 'Know what you have, and where.',
            'description' => 'Warehouses, transfers and reorder levels in one place, so you buy what you need before you run out and see stock value at a glance.',
            'features' => ['Multiple warehouses and stores', 'Transfers between locations', 'Reorder levels and alerts', 'Stock valuation reports'],
        ],
        [
            'code' => 'school',
            'name' => 'School',
            'tagline' => 'Admissions to report cards.',
            'description' => 'Run a school from enrolment to graduation: classes, timetables, attendance, fees and results, with parents kept in the loop.',
            'features' => ['Admissions and student records', 'Attendance and timetables', 'Fee invoices and payments', 'Exams, grades and report cards'],
        ],
        [
            'code' => 'hr',
            'name' => 'HR',
            'tagline' => 'Your people, organised.',
            'description' => 'Employee records, contracts, leave and documents for every branch and department, with approvals that follow your structure.',
            'features' => ['Employee records and documents', 'Leave requests and approvals', 'Contracts and probation tracking', 'Org chart by branch and department'],
        ],
        [
            'code' => 'payroll',
            'name' => 'Payroll',
            'tagline' => 'Pay everyone right, on time.',
            'description' => 'Salaries, allowances, deductions and payslips calculated from HR records, with payments posted straight into Finance.',
            'features' => ['Allowances and deductions', 'Payslips for every employee', 'Tax and statutory reports', 'Posts to Finance automatically'],
        ],
        [
            'code' => 'advanced_accounting',
            'name' => 'Advanced accounting',
            'tagline' => 'Budgets, cost centres and consolidation.',
            'description' => 'Budgets against actuals, cost centres per branch and department, multi-currency revaluation and consolidated statements across companies, on top of Finance.',
            'features' => ['Budgets and variance reports', 'Cost centres by branch and department', 'Multi-currency revaluation', 'Consolidated financial statements'],
        ],
        [
            'code' => 'crm',
            'name' => 'CRM',
            'tagline' => 'Every customer conversation in one place.',
            'description' => 'Track leads, deals and follow-ups so nothing slips, and see which customers bring the most business.',
            'features' => ['Leads and deal pipeline', 'Follow-up reminders', 'Customer history and notes', 'Sales performance reports'],
        ],
        [
            'code' => 'clinic',
            'name' => 'Clinic',
            'tagline' => 'Patients, appointments and billing.',
            'description' => 'Appointments, patient records, consultations and invoices for clinics and health centres, connected to the Pharmacy application.',
            'features' => ['Appointments and queues', 'Patient records and visit notes', 'Prescriptions sent to Pharmacy', 'Invoices and insurance claims'],
        ],
        [
            'code' => 'restaurant',
            'name' => 'Restaurant',
            'tagline' => 'From order to kitchen to bill.',
            'description' => 'Table orders, kitchen tickets and split bills for restaurants and cafés, with ingredient stock that falls as dishes are sold.',
            'features' => ['Table and takeaway orders', 'Kitchen order tickets', 'Split bills and tips', 'Ingredient stock by recipe'],
        ],
    ];

    public function run(): void
    {
        foreach (self::APPLICATIONS as $i => $application) {
            Application::query()->firstOrCreate(['code' => $application['code']], [
                'name' => $application['name'],
                'description' => $application['description'],
                'category' => 'products',
                'kind' => ApplicationKind::Standalone,
                'status' => ApplicationStatus::Planned,
                'capabilities' => ['marketing' => [
                    'tagline' => $application['tagline'],
                    'features' => $application['features'],
                ]],
                'sort_order' => 10 + $i,
            ]);
        }
    }
}
