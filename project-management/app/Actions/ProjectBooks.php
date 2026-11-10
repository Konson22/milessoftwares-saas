<?php

namespace App\Actions;

use App\Enums\CostCategory;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\Project;
use App\Support\FinanceEvents;
use Carbon\CarbonImmutable;

/**
 * Project money for the books, which are Finance's. Runs inside the
 * transaction that changes the expense or the grant payment, so the event
 * exists exactly when the payment does:
 *
 *   expense paid             finance.project_expense.paid    (the project as a dimension)
 *   expense voided           finance.project_expense.voided  (the mirror image)
 *   grant payment received   finance.grant.received
 *
 * Amounts go in the project's or grant's currency; Finance converts them at
 * its rate for the day. A commitment (approved, unpaid) is not sent: no
 * money has moved. Nor is labour: salaries reach the books from Payroll.
 */
final class ProjectBooks
{
    public const BANK_TRANSFER = 'bank_transfer';

    public const CASH = 'cash';

    public const MOBILE_MONEY = 'mobile_money';

    public const STAFF_ADVANCE = 'staff_advance';

    /** How money is paid out or in, as Finance is told. */
    public const METHODS = [self::BANK_TRANSFER, self::CASH, self::MOBILE_MONEY];

    public function __construct(private readonly FinanceEvents $events) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function methodOptions(): array
    {
        return [
            ['value' => self::BANK_TRANSFER, 'label' => 'Bank transfer'],
            ['value' => self::CASH, 'label' => 'Cash'],
            ['value' => self::MOBILE_MONEY, 'label' => 'Mobile money'],
        ];
    }

    public function expensePaid(Expense $expense, Project $project, string $method = self::BANK_TRANSFER): void
    {
        $this->spent(self::expenseReference($expense), [
            'paid_on' => CarbonImmutable::parse($expense->spent_on),
            'currency' => $project->currency,
            'amount_minor' => $expense->amount_minor,
            'description' => "{$project->code}: {$expense->description}",
            'category' => $expense->category,
            'method' => $method,
            'supplier' => $expense->supplier,
            'payment_reference' => $expense->payment_reference,
            'project' => $project,
        ]);
    }

    public function expenseVoided(Expense $expense, string $reason): void
    {
        $this->voided(self::expenseReference($expense), $reason);
    }

    /**
     * Money paid out that is not an expense row of Projects (petty cash
     * without a project, receipts against an advance).
     *
     * @param  array{paid_on: CarbonImmutable, currency: string, amount_minor: int, description: string, category: CostCategory, method: string, supplier?: string|null, payment_reference?: string|null, project?: Project|null}  $details
     */
    public function spent(string $reference, array $details): void
    {
        $project = $details['project'] ?? null;

        $this->events->send('finance.project_expense.paid', "{$reference}:paid", [
            'reference' => $reference,
            'paid_on' => $details['paid_on']->toDateString(),
            'currency' => $details['currency'],
            'amount_minor' => $details['amount_minor'],
            'description' => mb_substr($details['description'], 0, 255),
            'category' => $details['category']->value,
            'method' => $details['method'],
            'supplier' => $details['supplier'] ?? null,
            'payment_reference' => $details['payment_reference'] ?? null,
            'project' => $project === null ? null : ['code' => $project->code, 'name' => $project->name],
        ], $details['paid_on']);
    }

    public function voided(string $reference, string $reason): void
    {
        $this->events->send('finance.project_expense.voided', "{$reference}:voided", [
            'reference' => $reference,
            'voided_on' => CarbonImmutable::today()->toDateString(),
            'reason' => mb_substr($reason, 0, 255),
        ]);
    }

    public function grantReceived(GrantDisbursement $disbursement, Grant $grant): void
    {
        $on = CarbonImmutable::parse($disbursement->received_on);
        $grant->loadMissing('funder');

        $this->events->send('finance.grant.received', "grant-payment:{$disbursement->id}:received", [
            'reference' => "grant-payment:{$disbursement->id}",
            'grant_reference' => $grant->reference,
            'grant_title' => $grant->title,
            'funder' => $grant->funder->name,
            'received_on' => $on->toDateString(),
            'currency' => $grant->currency,
            'amount_minor' => $disbursement->amount_minor,
            'method' => self::BANK_TRANSFER,
        ], $on);
    }

    public static function expenseReference(Expense $expense): string
    {
        return "expense:{$expense->id}";
    }
}
