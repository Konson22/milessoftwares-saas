<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Enums\PaymentMethod;
use App\Finance\Banking;
use App\Finance\Budgets;
use App\Finance\FixedAssets;
use App\Finance\JournalApprovals;
use App\Finance\OpeningBalances;
use App\Finance\Payables;
use App\Finance\PaymentPolicy;
use App\Finance\PaymentRequests;
use App\Finance\Periods;
use App\Finance\Receivables;
use App\Finance\Reconciliation;
use App\Finance\RecurringJournals;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Bill;
use App\Models\DimensionValue;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\JournalLine;
use App\Models\PaymentRequest;
use App\Models\RecurringJournal;
use App\Models\Supplier;
use App\Models\TaxCode;
use App\Models\User;
use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * A year of books for the demo business central's DemoBusinessSeeder made
 * (DEMO_BUSINESS_FILE), a water utility: January 2026 to now, in USD, entered through the same
 * services the app uses, so every figure comes from the ledger. Ends with
 * approval on and something waiting at each step, for each role to see.
 * Amounts are in cents.
 */
class DemoBusinessSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $team = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            DB::transaction(function () use ($data, $organization) {
                app(SetUpWorkspace::class)->provision($organization);

                foreach ($data['users'] as $key => $user) {
                    $member = new User;
                    $member->forceFill($user)->save();
                    $this->team[$key] = $member;
                }

                $this->seedBooks();
            });
        });
    }

    private function seedBooks(): void
    {
        $owner = $this->team['owner'];
        $periods = app(Periods::class);
        $year = $periods->addYear(CarbonImmutable::parse('2026-01-01'));

        $admin = DimensionValue::query()->create(['kind' => DimensionValue::COST_CENTRE, 'code' => 'ADM', 'name' => 'Administration']);
        $sales = DimensionValue::query()->create(['kind' => DimensionValue::COST_CENTRE, 'code' => 'COM', 'name' => 'Commercial and billing']);
        $operations = DimensionValue::query()->create(['kind' => DimensionValue::COST_CENTRE, 'code' => 'OPS', 'name' => 'Water operations']);
        DimensionValue::query()->create(['kind' => DimensionValue::PROJECT, 'code' => 'NET', 'name' => 'Juba network rehabilitation']);

        $vat = TaxCode::query()->create(['code' => 'ST18', 'name' => 'Sales tax 18%', 'rate_bp' => 1800, 'sales' => true, 'purchases' => true, 'recoverable' => true, 'active' => true]);

        $account = fn (string $code) => Account::query()->where('code', $code)->sole();
        $bank = $account('1010');
        $cash = $account('1000');

        $petty = new Account(['code' => '1040', 'name' => 'Petty cash', 'type' => AccountType::Asset, 'bank_kind' => BankKind::PettyCash, 'active' => true]);
        $petty->save();
        $pounds = new Account(['code' => '1050', 'name' => 'SSP account', 'type' => AccountType::Asset, 'bank_kind' => BankKind::Bank, 'currency' => 'SSP', 'active' => true]);
        $pounds->save();
        ExchangeRate::query()->create(['currency' => 'SSP', 'rate_on' => '2026-01-01', 'rate' => '0.00022', 'created_by' => $owner->id]);

        app(OpeningBalances::class)->save([
            $bank->id => 8500000,
            $cash->id => 250000,
            $account('1400')->id => 1800000,
            $account('2500')->id => -2000000,
            $account('3000')->id => -8550000,
        ], $owner);

        $suppliers = [];
        foreach ([
            ['Nile Water Chemicals', 'orders@nile-chemicals.test', '+211 900 000 101'],
            ['Equatoria Haulage', 'accounts@equatoria-haulage.test', '+211 900 000 102'],
            ['Sunrise Office Supplies', 'sales@sunrise-office.test', '+211 900 000 103'],
            ['Imatong Pumps and Motors', 'service@imatong-pumps.test', '+211 900 000 104'],
        ] as [$name, $email, $phone]) {
            $suppliers[] = Supplier::query()->create(['name' => $name, 'email' => $email, 'phone' => $phone]);
        }

        $this->seedYear($bank, $cash, $petty, $pounds, $vat, $suppliers, compact('admin', 'sales', 'operations'));
        $this->seedAssets($bank, $suppliers[3], $vat);
        $this->seedBudget($year, $account);
        $this->seedStatement($bank);

        // What is still open today.
        $receivables = app(Receivables::class);
        $receivables->raise('Juba Water Vendors Association', CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-12-02'), [
            ['account' => $account('4000'), 'description' => 'November bulk water', 'amount_minor' => 1240000, 'tax_code' => $vat, 'cost_centre' => $sales],
        ], 0, $owner);
        $payables = app(Payables::class);
        $novemberBill = $payables->recordBill($suppliers[0], CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-30'), 'NWC-1146', [
            ['account' => $account('5000'), 'description' => 'November chlorine and alum', 'amount_minor' => 450000, 'tax_code' => null, 'cost_centre' => $operations],
        ], 0, null, $owner);

        // The months already closed.
        foreach (FiscalPeriod::query()->whereDate('ends_on', '<=', '2026-09-30')->orderBy('starts_on')->get() as $period) {
            $periods->lock($period, $this->team['chief_accountant']);
        }

        $this->seedApprovals($bank, $cash, $account, $suppliers[0], $novemberBill, $admin, $operations);
    }

    /**
     * @param  list<Supplier>  $suppliers
     * @param  array{admin: DimensionValue, sales: DimensionValue, operations: DimensionValue}  $centres
     */
    private function seedYear(Account $bank, Account $cash, Account $petty, Account $pounds, TaxCode $vat, array $suppliers, array $centres): void
    {
        $owner = $this->team['owner'];
        $receivables = app(Receivables::class);
        $payables = app(Payables::class);
        $banking = app(Banking::class);
        $account = fn (string $code) => Account::query()->where('code', $code)->sole();
        $customers = ['Juba Water Vendors Association', 'Munuki Residential Zone', 'Hai Malakal Commercial Zone', 'Kator Institutional Accounts', 'Gudele Water Kiosks'];

        for ($month = 1; $month <= 10; $month++) {
            $first = CarbonImmutable::create(2026, $month, 1);
            $day = fn (int $n) => $first->setDay($n);

            // Sales: one big customer and one smaller, paid within the month except lately.
            foreach ([[$customers[$month % 5], 1800000 + $month * 95000, 5], [$customers[($month + 2) % 5], 620000 + $month * 30000, 18]] as $index => [$customer, $amount, $on]) {
                $invoice = $receivables->raise($customer, $day($on), $day($on)->addDays(30), [
                    ['account' => $account('4000'), 'description' => $index === 0 ? 'Monthly water billing' : 'Kiosk water on account', 'amount_minor' => $amount, 'tax_code' => $vat, 'cost_centre' => $centres['sales']],
                ], 0, $owner);

                $total = $invoice->total_minor;
                if ($month <= 8) {
                    $receivables->receive($invoice, $bank, PaymentMethod::BankTransfer, $total, $day($on)->addDays(20), null, $owner);
                } elseif ($month === 9 && $index === 0) {
                    $receivables->receive($invoice, $bank, PaymentMethod::MobileMoney, (int) round($total / 2), $day($on)->addDays(25), 'Part payment', $owner);
                }
            }

            // Stock, freight and supplies on credit; paid the month after, except lately.
            foreach ([
                [$suppliers[0], '5000', 'Chlorine and alum', 1050000 + $month * 40000, 3, $centres['operations']],
                [$suppliers[1], '6900', 'Chemical haulage from Nimule', 210000, 9, $centres['operations']],
            ] as [$supplier, $code, $description, $amount, $on, $centre]) {
                $bill = $payables->recordBill($supplier, $day($on), $day($on)->addDays(30), strtoupper(substr($supplier->name, 0, 2))."-{$month}0{$on}", [
                    ['account' => $account($code), 'description' => $description, 'amount_minor' => $amount, 'tax_code' => null, 'cost_centre' => $centre],
                ], 0, null, $owner);

                if ($month <= 8) {
                    $payables->pay($supplier, $bank, $amount, $day($on)->addDays(25), [$bill->id => $amount], "TRF-{$month}{$on}", null, $owner);
                }
            }

            // Running costs paid straight from the bank.
            $banking->moneyOut($bank, $account('6000'), 240000, $day(1), 'Office and depot rent', $owner, null, ['cost_centre' => $centres['admin']]);
            $banking->moneyOut($bank, $account('6100'), 310000, $day(28), 'Salaries: billing team', $owner, null, ['cost_centre' => $centres['sales']]);
            $banking->moneyOut($bank, $account('6100'), 280000, $day(28), 'Salaries: plant and network crews', $owner, null, ['cost_centre' => $centres['operations']]);
            $banking->moneyOut($bank, $account('6100'), 190000, $day(28), 'Salaries: administration', $owner, null, ['cost_centre' => $centres['admin']]);
            $banking->moneyOut($bank, $account('6200'), 36000 + $month * 1500, $day(12), 'Power for the pumping stations', $owner, null, ['cost_centre' => $centres['admin']]);
            $banking->moneyOut($bank, $account('6300'), 8500, $day(27), 'Bank charges', $owner);

            // Cash keeps moving between the accounts.
            $banking->transfer($bank, $cash, 100000, $day(2), 'Cash for the customer service counter', $owner);
            $banking->transfer($cash, $petty, 15000, $day(3), 'Petty cash top up', $owner);
            $banking->moneyOut($petty, $account('6900'), 11000, $day(20), 'Sundry office expenses', $owner, null, ['cost_centre' => $centres['admin']]);

            // Households paying in South Sudanese pounds.
            $banking->moneyIn($pounds, $account('4900'), 180000000, $day(15), 'Water bills paid in SSP', $owner);
        }

        // Older debts, for the aged reports.
        $old = $receivables->raise('Konyokonyo Market Traders', CarbonImmutable::parse('2026-06-10'), CarbonImmutable::parse('2026-07-10'), [
            ['account' => $account('4000'), 'description' => 'Metered water, disputed reading', 'amount_minor' => 385000, 'tax_code' => $vat, 'cost_centre' => $centres['sales']],
        ], 0, $owner);
        $receivables->receive($old, $bank, PaymentMethod::Cash, 100000, CarbonImmutable::parse('2026-07-20'), 'Part payment', $owner);
    }

    private function seedAssets(Account $bank, Supplier $motors, TaxCode $vat): void
    {
        $owner = $this->team['owner'];
        $account = fn (string $code) => Account::query()->where('code', $code)->sole();

        $bill = app(Payables::class)->recordBill($motors, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-16'), 'IPM-2031', [
            ['account' => $account('1500'), 'description' => 'Water tanker, 18,000 litres', 'amount_minor' => 2400000, 'tax_code' => null],
        ], 0, null, $owner);
        app(Payables::class)->pay($motors, $bank, 2400000, CarbonImmutable::parse('2026-03-12'), [$bill->id => 2400000], 'TRF-TRUCK', null, $owner);

        $category = AssetCategory::query()->create([
            'name' => 'Vehicles',
            'asset_account_id' => $account('1500')->id,
            'accumulated_account_id' => $account('1590')->id,
            'expense_account_id' => $account('6400')->id,
            'method' => 'straight_line',
            'life_months' => 60,
            'active' => true,
        ]);

        $assets = app(FixedAssets::class);
        $assets->register($category, 'Water tanker, 18,000 litres', CarbonImmutable::parse('2026-03-02'), 2400000, ['residual' => 240000], null, $bill, $owner);

        for ($month = 3; $month <= 9; $month++) {
            $assets->run(CarbonImmutable::create(2026, $month, 1), $owner);
        }
    }

    /**
     * @param  callable(string): Account  $account
     */
    private function seedBudget(mixed $year, callable $account): void
    {
        $budgets = app(Budgets::class);
        $budget = $budgets->create($year, '2026 operating budget', null, false, '0', $this->team['finance_manager']);

        $plan = [
            '4000' => 3200000, '5000' => 1300000, '6000' => 240000, '6100' => 760000,
            '6200' => 45000, '6300' => 9000, '6400' => 36000, '6900' => 240000,
        ];
        $amounts = [];
        foreach ($plan as $code => $monthly) {
            $amounts[$account((string) $code)->id] = array_fill(1, 12, $monthly);
        }

        $budgets->save($budget, $amounts);
    }

    /**
     * October's statement from the bank: matched by itself except for a
     * cheque not yet presented and a charge not yet in the books.
     */
    private function seedStatement(Account $bank): void
    {
        $owner = $this->team['chief_accountant'];
        $reconciliation = app(Reconciliation::class);
        $base = JournalLine::query()->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->where('journal_lines.account_id', $bank->id);

        $opening = (int) (clone $base)->whereDate('journals.entry_date', '<', '2026-10-01')->sum('journal_lines.debit_minor')
            - (int) (clone $base)->whereDate('journals.entry_date', '<', '2026-10-01')->sum('journal_lines.credit_minor');

        $lines = (clone $base)->whereDate('journals.entry_date', '>=', '2026-10-01')->whereDate('journals.entry_date', '<=', '2026-10-31')
            ->orderBy('journals.entry_date')->get(['journal_lines.debit_minor', 'journal_lines.credit_minor', 'journals.entry_date', 'journals.memo', 'journals.number']);

        $rows = [];
        foreach ($lines->slice(0, max(0, $lines->count() - 1)) as $line) {
            $rows[] = [
                'date' => CarbonImmutable::parse((string) $line->getAttribute('entry_date')),
                'description' => (string) $line->getAttribute('memo'),
                'reference' => (string) $line->getAttribute('number'),
                'amount' => (int) $line->debit_minor - (int) $line->credit_minor,
            ];
        }
        $rows[] = ['date' => CarbonImmutable::parse('2026-10-30'), 'description' => 'Card machine fee', 'reference' => 'CHG-1031', 'amount' => -4200];

        $closing = $opening + (int) array_sum(array_column($rows, 'amount'));
        $statement = $reconciliation->import($bank, CarbonImmutable::parse('2026-10-31'), $opening, $closing, 'OCT-2026', $rows, $owner)['statement'];
        $reconciliation->autoMatch($statement, $owner);
    }

    /**
     * @param  callable(string): Account  $account
     */
    private function seedApprovals(Account $bank, Account $cash, callable $account, Supplier $supplier, Bill $bill, DimensionValue $admin, DimensionValue $operations): void
    {
        app(PaymentPolicy::class)->configure(100000, true);
        $journals = app(JournalApprovals::class);
        $journals->configure(true, 500000);

        $requests = app(PaymentRequests::class);
        $officer = $this->team['finance_officer'];
        $reviewer = $this->team['accountant'];
        $manager = $this->team['finance_manager'];
        $cashier = $this->team['cashier'];
        $on = CarbonImmutable::parse('2026-11-04');
        $expense = fn (string $description, int $amount, string $code, DimensionValue $centre, string $payee) => $requests->submit(PaymentRequest::EXPENSE, $bank, $amount, $on, $description, $officer, [
            'other_account' => $account($code), 'cost_centre' => $centre, 'payee' => $payee,
        ]);

        // One waiting at each step.
        $expense('Booster pump repair', 320000, '6900', $operations, 'Imatong Pumps and Motors');

        $toApprove = $requests->submit(PaymentRequest::SUPPLIER, $bank, 450000, $on, 'November chlorine and alum', $officer, [
            'supplier' => $supplier, 'allocations' => [$bill->id => 450000], 'reference' => 'TRF-1146',
        ]);
        $requests->review($toApprove, $reviewer);

        $toPay = $requests->submit(PaymentRequest::ADVANCE, $bank, 150000, $on, 'Travel advance to the Bor station', $officer, ['payee' => $cashier->name, 'cost_centre' => $admin]);
        $requests->review($toPay, $reviewer);
        $requests->approve($toPay, $manager);

        $paid = $expense('Treatment plant security, October', 200000, '6900', $operations, 'Guard Services');
        $requests->review($paid, $reviewer);
        $requests->approve($paid, $manager);
        $requests->disburse($paid, CarbonImmutable::parse('2026-11-03'), 'TRF-2204', $cashier);

        $refused = $expense('New office chairs', 180000, '6900', $admin, 'Sunrise Office Supplies');
        $requests->review($refused, $reviewer);
        $requests->reject($refused, 'Not in this quarter\'s budget', $manager);

        // Below the limit: no approval, only someone else to pay it.
        $requests->submit(PaymentRequest::TRANSFER, $bank, 40000, $on, 'Top up the till', $officer, ['other_account' => $cash]);

        // A large journal waiting for the chief accountant.
        $journals->submit($on, 'Accrue October audit fee', [
            ['account_id' => $account('6900')->id, 'debit_minor' => 650000, 'credit_minor' => 0, 'description' => 'Audit fee', 'cost_centre_id' => $admin->id, 'project_id' => null],
            ['account_id' => $account('2000')->id, 'debit_minor' => 0, 'credit_minor' => 650000, 'description' => null, 'cost_centre_id' => null, 'project_id' => null],
        ], $reviewer);

        // Recurring: one posts by itself, one waits for a payment approver.
        $recurring = app(RecurringJournals::class);
        $stored = fn (string $debit, string $credit, int $amount) => [
            ['account_id' => $account($debit)->id, 'debit_minor' => $amount, 'credit_minor' => 0, 'description' => null, 'cost_centre_id' => null, 'project_id' => null],
            ['account_id' => $account($credit)->id, 'debit_minor' => 0, 'credit_minor' => $amount, 'description' => null, 'cost_centre_id' => null, 'project_id' => null],
        ];
        $recurring->save(null, ['name' => 'Insurance accrual', 'memo' => 'Monthly insurance accrual', 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => CarbonImmutable::parse('2026-11-30'), 'ends_on' => null, 'reverses' => false, 'lines' => $stored('6900', '2000', 75000)], $reviewer);
        $recurring->save(null, ['name' => 'Loan repayment', 'memo' => 'Monthly loan repayment', 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => CarbonImmutable::parse('2026-11-30'), 'ends_on' => null, 'reverses' => false, 'lines' => $stored('2500', '1010', 150000)], $reviewer);
    }
}
