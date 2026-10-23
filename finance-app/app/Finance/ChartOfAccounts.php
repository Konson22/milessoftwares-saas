<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Models\Account;

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
        ['code' => '1400', 'name' => 'Inventory', 'type' => AccountType::Asset, 'key' => Account::INVENTORY],
        ['code' => '1500', 'name' => 'Equipment', 'type' => AccountType::Asset],
        ['code' => '1590', 'name' => 'Accumulated depreciation', 'type' => AccountType::Asset],
        ['code' => '2000', 'name' => 'Accounts payable', 'type' => AccountType::Liability, 'key' => Account::PAYABLE],
        ['code' => '2200', 'name' => 'Tax payable', 'type' => AccountType::Liability, 'key' => Account::TAX],
        ['code' => '2500', 'name' => 'Loans', 'type' => AccountType::Liability],
        ['code' => '3000', 'name' => "Owner's equity", 'type' => AccountType::Equity, 'key' => Account::EQUITY],
        ['code' => '3100', 'name' => 'Retained earnings', 'type' => AccountType::Equity, 'key' => Account::RETAINED_EARNINGS],
        ['code' => '3900', 'name' => 'Opening balance equity', 'type' => AccountType::Equity, 'key' => Account::OPENING_BALANCE],
        ['code' => '4000', 'name' => 'Sales revenue', 'type' => AccountType::Revenue, 'key' => Account::SALES],
        ['code' => '4900', 'name' => 'Other income', 'type' => AccountType::Revenue],
        ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => AccountType::Expense, 'key' => Account::PURCHASES],
        ['code' => '5100', 'name' => 'Stock losses and write-offs', 'type' => AccountType::Expense, 'key' => Account::STOCK_LOSSES],
        ['code' => '6000', 'name' => 'Rent', 'type' => AccountType::Expense],
        ['code' => '6100', 'name' => 'Salaries and wages', 'type' => AccountType::Expense],
        ['code' => '6200', 'name' => 'Utilities', 'type' => AccountType::Expense],
        ['code' => '6300', 'name' => 'Bank and mobile money charges', 'type' => AccountType::Expense],
        ['code' => '6400', 'name' => 'Depreciation', 'type' => AccountType::Expense],
        ['code' => '6800', 'name' => 'Cash over and short', 'type' => AccountType::Expense, 'key' => Account::CASH_OVER_SHORT],
        ['code' => '6900', 'name' => 'Other expenses', 'type' => AccountType::Expense, 'key' => Account::OTHER_EXPENSES],
        ['code' => '6950', 'name' => 'Exchange gains and losses', 'type' => AccountType::Expense, 'key' => Account::FX],
        ['code' => '6960', 'name' => 'Gain or loss on disposal of assets', 'type' => AccountType::Expense, 'key' => Account::DISPOSAL],
    ];

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
