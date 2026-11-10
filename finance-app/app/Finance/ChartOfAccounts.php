<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Models\Account;
use InvalidArgumentException;

/**
 * The chart every organization starts with. The accounts with a system key
 * are the ones the posting rules use; the rest are common starting points
 * the accountant can rename or add to.
 */
class ChartOfAccounts
{
    /**
     * @var list<array{code: string, name: string, type: AccountType, key?: string, bank?: BankKind}>
     */
    private const DEFAULTS = [
        ['code' => '1000', 'name' => 'Cash on hand', 'type' => AccountType::Asset, 'key' => Account::CASH, 'bank' => BankKind::Cash],
        ['code' => '1010', 'name' => 'Bank account', 'type' => AccountType::Asset, 'key' => Account::BANK, 'bank' => BankKind::Bank],
        ['code' => '1020', 'name' => 'Mobile money', 'type' => AccountType::Asset, 'key' => Account::MOBILE_MONEY, 'bank' => BankKind::MobileMoney],
        ['code' => '1030', 'name' => 'Card settlements', 'type' => AccountType::Asset, 'key' => Account::CARD, 'bank' => BankKind::Card],
        ['code' => '1200', 'name' => 'Accounts receivable', 'type' => AccountType::Asset, 'key' => Account::RECEIVABLE],
        ['code' => '1250', 'name' => 'Employee advances', 'type' => AccountType::Asset, 'key' => Account::EMPLOYEE_ADVANCES],
        ['code' => '1400', 'name' => 'Inventory', 'type' => AccountType::Asset, 'key' => Account::INVENTORY],
        ['code' => '1500', 'name' => 'Equipment', 'type' => AccountType::Asset],
        ['code' => '1590', 'name' => 'Accumulated depreciation', 'type' => AccountType::Asset],
        ['code' => '2000', 'name' => 'Accounts payable', 'type' => AccountType::Liability, 'key' => Account::PAYABLE],
        ['code' => '2200', 'name' => 'Tax payable', 'type' => AccountType::Liability, 'key' => Account::TAX],
        ['code' => '2210', 'name' => 'Income tax withheld (PAYE)', 'type' => AccountType::Liability, 'key' => Account::PAYE],
        ['code' => '2300', 'name' => 'Salaries payable', 'type' => AccountType::Liability, 'key' => Account::SALARIES_PAYABLE],
        ['code' => '2310', 'name' => 'Social security payable', 'type' => AccountType::Liability, 'key' => Account::SOCIAL_SECURITY_PAYABLE],
        ['code' => '2320', 'name' => 'Other payroll deductions payable', 'type' => AccountType::Liability, 'key' => Account::PAYROLL_DEDUCTIONS],
        ['code' => '2500', 'name' => 'Loans', 'type' => AccountType::Liability],
        ['code' => '3000', 'name' => "Owner's equity", 'type' => AccountType::Equity, 'key' => Account::EQUITY],
        ['code' => '3100', 'name' => 'Retained earnings', 'type' => AccountType::Equity, 'key' => Account::RETAINED_EARNINGS],
        ['code' => '3900', 'name' => 'Opening balance equity', 'type' => AccountType::Equity, 'key' => Account::OPENING_BALANCE],
        ['code' => '4000', 'name' => 'Sales revenue', 'type' => AccountType::Revenue, 'key' => Account::SALES],
        ['code' => '4900', 'name' => 'Other income', 'type' => AccountType::Revenue],
        ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => AccountType::Expense, 'key' => Account::PURCHASES],
        ['code' => '5100', 'name' => 'Stock losses and write-offs', 'type' => AccountType::Expense, 'key' => Account::STOCK_LOSSES],
        ['code' => '6000', 'name' => 'Rent', 'type' => AccountType::Expense],
        ['code' => '6100', 'name' => 'Salaries and wages', 'type' => AccountType::Expense, 'key' => Account::SALARIES],
        ['code' => '6110', 'name' => 'Employer social security', 'type' => AccountType::Expense, 'key' => Account::EMPLOYER_CONTRIBUTIONS],
        ['code' => '6200', 'name' => 'Utilities', 'type' => AccountType::Expense],
        ['code' => '6300', 'name' => 'Bank and mobile money charges', 'type' => AccountType::Expense],
        ['code' => '6400', 'name' => 'Depreciation', 'type' => AccountType::Expense],
        ['code' => '6800', 'name' => 'Cash over and short', 'type' => AccountType::Expense, 'key' => Account::CASH_OVER_SHORT],
        ['code' => '6900', 'name' => 'Other expenses', 'type' => AccountType::Expense, 'key' => Account::OTHER_EXPENSES],
        ['code' => '6950', 'name' => 'Exchange gains and losses', 'type' => AccountType::Expense, 'key' => Account::FX],
        ['code' => '6960', 'name' => 'Gain or loss on disposal of assets', 'type' => AccountType::Expense, 'key' => Account::DISPOSAL],
    ];

    /**
     * Accounts only an organization that needs them gets, the first time an
     * event uses one (Projects: grants, project costs, advances written off).
     *
     * @var list<array{code: string, name: string, type: AccountType, key: string}>
     */
    private const ON_DEMAND = [
        ['code' => '4100', 'name' => 'Grant income', 'type' => AccountType::Revenue, 'key' => Account::GRANT_INCOME],
        ['code' => '6510', 'name' => 'Project materials and supplies', 'type' => AccountType::Expense, 'key' => Account::PROJECT_MATERIALS],
        ['code' => '6520', 'name' => 'Project equipment', 'type' => AccountType::Expense, 'key' => Account::PROJECT_EQUIPMENT],
        ['code' => '6530', 'name' => 'Project travel', 'type' => AccountType::Expense, 'key' => Account::PROJECT_TRAVEL],
        ['code' => '6540', 'name' => 'Subcontracted services', 'type' => AccountType::Expense, 'key' => Account::PROJECT_SUBCONTRACT],
        ['code' => '6850', 'name' => 'Staff advances written off', 'type' => AccountType::Expense, 'key' => Account::ADVANCES_WRITTEN_OFF],
    ];

    /**
     * A payroll account by system key. An organization whose chart predates
     * Payroll gets it now: its keyless account with the default code is
     * adopted when it is of the right type (6100 Salaries and wages), else a
     * new one takes the next free code.
     */
    public function payrollAccount(string $key): Account
    {
        return $this->onDemand($key);
    }

    /**
     * An account by system key, made the first time it is needed: a keyless
     * account with the default code is adopted when it is of the right type,
     * else a new one takes the next free code.
     */
    public function onDemand(string $key): Account
    {
        $this->ensure();

        $found = Account::query()->where('system_key', $key)->first();
        if ($found !== null) {
            return $found;
        }

        $default = null;
        foreach ([...self::DEFAULTS, ...self::ON_DEMAND] as $candidate) {
            if (($candidate['key'] ?? null) === $key) {
                $default = $candidate;
            }
        }
        if ($default === null) {
            throw new InvalidArgumentException("No default account for [{$key}].");
        }

        $existing = Account::query()->where('code', $default['code'])->first();
        if ($existing !== null && $existing->system_key === null && $existing->type === $default['type']) {
            $existing->system_key = $key;
            $existing->save();

            return $existing;
        }

        $codes = Account::query()->pluck('code')->all();
        $code = (int) $default['code'];
        while (in_array((string) $code, $codes, true)) {
            $code++;
        }

        $account = new Account(['code' => (string) $code, 'name' => $default['name'], 'type' => $default['type'], 'active' => true]);
        $account->system_key = $key;
        $account->save();

        return $account;
    }

    /**
     * The employee advances account; an organization already using its code
     * gets the next free one.
     */
    public function advances(): Account
    {
        $this->ensure();

        $found = Account::query()->where('system_key', Account::EMPLOYEE_ADVANCES)->first();
        if ($found !== null) {
            return $found;
        }

        $codes = Account::query()->pluck('code')->all();
        $code = 1250;
        while (in_array((string) $code, $codes, true)) {
            $code++;
        }

        $account = new Account(['code' => (string) $code, 'name' => 'Employee advances', 'type' => AccountType::Asset]);
        $account->system_key = Account::EMPLOYEE_ADVANCES;
        $account->save();

        return $account;
    }

    /**
     * Creates whatever of the starting chart is missing; safe to run again.
     * An account the accountant renamed keeps its name.
     */
    public function ensure(): void
    {
        $existing = Account::query()->get(['code', 'system_key']);
        $codes = $existing->pluck('code')->all();
        $keys = $existing->pluck('system_key')->filter()->all();

        foreach (self::DEFAULTS as $default) {
            $key = $default['key'] ?? null;

            if (in_array($default['code'], $codes, true) || ($key !== null && in_array($key, $keys, true))) {
                continue;
            }

            $account = new Account([
                'code' => $default['code'],
                'name' => $default['name'],
                'type' => $default['type'],
                'bank_kind' => $default['bank'] ?? null,
            ]);
            $account->system_key = $key;
            $account->save();
        }
    }
}
