<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Bill;
use App\Models\CustomerPayment;
use App\Models\DimensionValue;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PettyCashFund;
use App\Models\Supplier;
use App\Models\SupplierCredit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Miles\SharedModuleKit\Envelope;

/**
 * Turns a product's business event into the books, inside the inbox's
 * transaction (so it happens exactly once per business transaction).
 *
 *   finance.invoice.issued    Dr receivable        Cr revenue (+ tax payable)
 *   finance.payment.received  Dr cash/bank/...     Cr receivable
 *   finance.invoice.voided    Dr revenue (+ tax)   Cr receivable, and a refund:
 *                             Dr receivable        Cr cash/bank/...
 *   finance.sales_return.issued
 *                             Dr revenue (+ tax)   Cr receivable, set against its invoice,
 *                             and a refund: Dr receivable  Cr cash/bank/...
 *   finance.stock.adjusted    opening: Dr inventory  Cr owner's equity;
 *                             count, write_off: Dr stock losses  Cr inventory
 *                             (a gain the other way round)
 *   finance.cash.counted      short: Dr cash over and short  Cr cash (over the reverse)
 *   finance.cash.moved        banked: Dr bank  Cr cash; from the bank the reverse;
 *                             paid out: Dr the expense (Other expenses)  Cr cash
 *   finance.bill.received     Dr inventory (or the line's account) (+ tax)
 *                                                  Cr payable
 *   finance.credit_note.received
 *                             Dr payable           Cr inventory (or the line's account) (+ tax),
 *                                                  set against its bill
 *   finance.payroll.posted    Dr salaries, employer social security
 *                             Cr PAYE, social security payable, other deductions
 *                             payable, salaries payable (run totals only)
 *   finance.payroll.paid      Dr salaries payable  Cr bank/cash/mobile money
 *   finance.project_expense.paid
 *                             Dr the cost category (project dimension)
 *                                                  Cr bank/cash/mobile money/employee advances
 *   finance.project_expense.voided  the mirror of that journal
 *   finance.grant.received    Dr bank/cash/mobile money  Cr grant income
 *   finance.staff_advance.issued   Dr employee advances  Cr bank/cash/mobile money
 *   finance.staff_advance.settled  returned: Dr bank/cash/mobile money  Cr employee advances;
 *                             written off: Dr advances written off  Cr employee advances
 *   finance.petty_cash.transferred  a Projects petty cash box becomes a Finance fund on
 *                             an account of its own: Dr that account  Cr cash (where its
 *                             till events had gone)
 *
 * Sales carrying a cost also move it: Dr cost of goods sold / Cr inventory
 * (returns put back on the shelf, the other way round).
 */
class PostEvent
{
    /**
     * Events whose amounts may be in another currency than the books': each
     * is converted at Finance's rate for its day (and waits for one).
     */
    public const CONVERTED = [
        'finance.project_expense.paid',
        'finance.grant.received',
        'finance.staff_advance.issued',
        'finance.staff_advance.settled',
        'finance.petty_cash.transferred',
    ];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * @throws NotYetPostable when it depends on an event that has not arrived (the product retries)
     * @throws UnsupportedEvent for an event type or version Finance does not handle
     */
    public function handle(Envelope $event): void
    {
        // An organization entitled before its chart existed gets it now.
        $this->chart->ensure();

        match ("{$event->eventType}.v{$event->schemaVersion}") {
            'finance.invoice.issued.v1' => $this->invoiceIssued($event),
            'finance.payment.received.v1' => $this->paymentReceived($event),
            'finance.invoice.voided.v1' => $this->invoiceVoided($event),
            'finance.sales_return.issued.v1' => $this->salesReturnIssued($event),
            'finance.stock.adjusted.v1' => $this->stockAdjusted($event),
            'finance.cash.counted.v1' => $this->cashCounted($event),
            'finance.cash.moved.v1' => $this->cashMoved($event),
            'finance.bill.received.v1' => $this->billReceived($event),
            'finance.credit_note.received.v1' => $this->creditNoteReceived($event),
            'finance.payroll.posted.v1' => $this->payrollPosted($event),
            'finance.payroll.paid.v1' => $this->payrollPaid($event),
            'finance.project_expense.paid.v1' => $this->projectExpensePaid($event),
            'finance.project_expense.voided.v1' => $this->projectExpenseVoided($event),
            'finance.grant.received.v1' => $this->grantReceived($event),
            'finance.staff_advance.issued.v1' => $this->staffAdvanceIssued($event),
            'finance.staff_advance.settled.v1' => $this->staffAdvanceSettled($event),
            'finance.petty_cash.transferred.v1' => $this->pettyCashTransferred($event),
            default => throw new UnsupportedEvent("Finance does not handle {$event->eventType} v{$event->schemaVersion}."),
        };
    }

    private function invoiceIssued(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'revenue_account');

        $journalLines = [];
        if ($total > 0) {
            $journalLines[] = ['account' => Account::system(Account::RECEIVABLE), 'debit' => $total, 'description' => $p['customer_reference'] ?? null];
            foreach ($this->split($lines, $total - $tax, Account::SALES) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'credit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $tax, 'tax' => ['kind' => JournalLine::OUTPUT, 'base' => $total - $tax]];
            }
        }

        // The goods leave inventory at what they cost: cost of goods sold.
        $cost = array_sum(array_map(fn (array $line) => $line['cost_minor'] ?? 0, $lines));
        if ($cost > 0) {
            $journalLines[] = ['account' => Account::system(Account::PURCHASES), 'debit' => $cost, 'description' => 'Cost of goods sold'];
            $journalLines[] = ['account' => Account::system(Account::INVENTORY), 'credit' => $cost];
        }

        $invoice = new Invoice([
            'number' => $p['invoice_number'],
            'source_app' => $event->sourceApp,
            'customer_name' => $p['customer_reference'] ?? null,
            'branch_id' => $p['branch_id'] ?? null,
            'issued_on' => $date,
            'due_on' => $p['due_on'] ?? null,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'lines' => $lines,
        ]);

        // Nothing charged and nothing at cost posts nothing; the invoice is
        // still kept for voids.
        if ($journalLines !== []) {
            $invoice->journal_id = $this->ledger->post($date, "Invoice {$p['invoice_number']}", $journalLines, $this->source($event, $p['invoice_number']))->id;
        }
        $invoice->save();

        // Payments that arrived first.
        $early = CustomerPayment::query()
            ->where('source_app', $event->sourceApp)
            ->where('invoice_number', $invoice->number)
            ->whereNull('invoice_id')
            ->get();
        foreach ($early as $payment) {
            $payment->forceFill(['invoice_id' => $invoice->id])->save();
        }
        $this->refreshPaid($invoice);
    }

    private function paymentReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $amount = (int) $p['amount_minor'];
        $method = PaymentMethod::from($p['method']);
        $account = $this->moneyAccount($method);
        $invoice = isset($p['invoice_number'])
            ? Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->first()
            : null;

        $journal = $this->ledger->post($date, "Payment {$p['payment_reference']}", [
            ['account' => $account, 'debit' => $amount],
            ['account' => Account::system(Account::RECEIVABLE), 'credit' => $amount],
        ], $this->source($event, $p['payment_reference']));

        CustomerPayment::query()->create([
            'number' => $p['payment_reference'],
            'source_app' => $event->sourceApp,
            'kind' => CustomerPayment::RECEIPT,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $p['invoice_number'] ?? null,
            'method' => $method,
            'amount_minor' => $amount,
            'received_on' => $date,
            'account_id' => $account->id,
            'processor_reference' => $p['processor_reference'] ?? null,
            'journal_id' => $journal->id,
        ]);

        if ($invoice !== null) {
            $this->refreshPaid($invoice);
        }
    }

    private function invoiceVoided(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $invoice = Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->lockForUpdate()->first();

        if ($invoice === null) {
            throw new NotYetPostable("Invoice {$p['invoice_number']} has not arrived yet.");
        }
        if ($invoice->voided_at !== null) {
            return;
        }

        // The invoice's own journal, mirrored.
        $voidJournal = $invoice->journal_id === null ? null : $this->ledger->reverse(
            Journal::query()->findOrFail($invoice->journal_id),
            $date,
            "Void of invoice {$invoice->number}: {$p['reason']}",
            sourceType: $event->eventType,
            periods: Periods::MOVE,
        );
        $invoice->forceFill([
            'voided_at' => CarbonImmutable::instance($event->occurredAt),
            'void_reason' => $p['reason'],
            'void_journal_id' => $voidJournal?->id,
        ])->save();

        $refund = $p['refund'] ?? null;
        if (is_array($refund) && (int) $refund['amount_minor'] > 0) {
            $method = PaymentMethod::from($refund['method']);
            $account = $this->moneyAccount($method);
            $amount = (int) $refund['amount_minor'];
            $reference = ($refund['payment_reference'] ?? $invoice->number).'-REFUND';

            $journal = $this->ledger->post($date, "Refund for invoice {$invoice->number}", [
                ['account' => Account::system(Account::RECEIVABLE), 'debit' => $amount],
                ['account' => $account, 'credit' => $amount],
            ], $this->source($event, $reference));

            CustomerPayment::query()->create([
                'number' => $reference,
                'source_app' => $event->sourceApp,
                'kind' => CustomerPayment::REFUND,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'method' => $method,
                'amount_minor' => $amount,
                'received_on' => $date,
                'account_id' => $account->id,
                'memo' => $p['reason'],
                'journal_id' => $journal->id,
            ]);
            $this->refreshPaid($invoice);
        }
    }

    /**
     * Part of a sale brought back: the revenue reversed against its
     * invoice, the cost of what goes back on the shelf back to inventory,
     * and the refund.
     */
    private function salesReturnIssued(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::instance($event->occurredAt)->startOfDay();
        $invoice = Invoice::query()->where('source_app', $event->sourceApp)->where('number', $p['invoice_number'])->lockForUpdate()->first();

        if ($invoice === null) {
            throw new NotYetPostable("Invoice {$p['invoice_number']} has not arrived yet.");
        }
        if ($invoice->voided_at !== null) {
            return;
        }

        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'revenue_account');

        $journalLines = [];
        if ($total > 0) {
            foreach ($this->split($lines, $total - $tax, Account::SALES) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'debit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'debit' => $tax, 'tax' => ['kind' => JournalLine::OUTPUT, 'base' => $tax - $total]];
            }
            $journalLines[] = ['account' => Account::system(Account::RECEIVABLE), 'credit' => $total, 'description' => $invoice->customer_name];
        }
        $cost = array_sum(array_map(fn (array $line) => $line['cost_minor'] ?? 0, $lines));
        if ($cost > 0) {
            $journalLines[] = ['account' => Account::system(Account::INVENTORY), 'debit' => $cost, 'description' => 'Returned to stock'];
            $journalLines[] = ['account' => Account::system(Account::PURCHASES), 'credit' => $cost];
        }
        if ($journalLines !== []) {
            $this->ledger->post($date, "Return {$p['return_number']} on invoice {$invoice->number}: {$p['reason']}", $journalLines, $this->source($event, $p['return_number']));
        }
        $invoice->forceFill(['credited_minor' => $invoice->credited_minor + $total])->save();

        $refund = $p['refund'] ?? null;
        if (is_array($refund) && (int) $refund['amount_minor'] > 0) {
            $method = PaymentMethod::from($refund['method']);
            $account = $this->moneyAccount($method);
            $amount = (int) $refund['amount_minor'];
            $reference = $refund['payment_reference'] ?? $p['return_number'];

            $journal = $this->ledger->post($date, "Refund for return {$p['return_number']}", [
                ['account' => Account::system(Account::RECEIVABLE), 'debit' => $amount],
                ['account' => $account, 'credit' => $amount],
            ], $this->source($event, "{$reference}-REFUND"));

            CustomerPayment::query()->create([
                'number' => $reference,
                'source_app' => $event->sourceApp,
                'kind' => CustomerPayment::REFUND,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'method' => $method,
                'amount_minor' => $amount,
                'received_on' => $date,
                'account_id' => $account->id,
                'memo' => $p['reason'],
                'journal_id' => $journal->id,
            ]);
        }
        $this->refreshPaid($invoice);
    }

    /**
     * Stock found, lost or destroyed, or on the shelf from before: at cost,
     * against inventory.
     */
    private function stockAdjusted(Envelope $event): void
    {
        $p = $event->payload;
        $amount = (int) $p['amount_minor'];
        if ($amount === 0) {
            return;
        }

        $other = Account::system($p['kind'] === 'opening' ? Account::EQUITY : Account::STOCK_LOSSES);
        $inventory = Account::system(Account::INVENTORY);
        [$debit, $credit] = $amount > 0 ? [$inventory, $other] : [$other, $inventory];
        $label = match ($p['kind']) {
            'opening' => 'Opening stock',
            'write_off' => 'Stock written off',
            default => 'Stock count',
        };

        $this->ledger->post(CarbonImmutable::parse($p['adjusted_on']), "{$label} {$p['reference']}: {$p['reason']}", [
            ['account' => $debit, 'debit' => abs($amount)],
            ['account' => $credit, 'credit' => abs($amount)],
        ], $this->source($event, $p['reference']));
    }

    /**
     * A drawer that held more or less cash than it should.
     */
    private function cashCounted(Envelope $event): void
    {
        $p = $event->payload;
        $difference = (int) $p['difference_minor'];
        if ($difference === 0) {
            return;
        }

        $cash = Account::system(Account::CASH);
        $overShort = Account::system(Account::CASH_OVER_SHORT);
        [$debit, $credit] = $difference > 0 ? [$cash, $overShort] : [$overShort, $cash];

        $this->ledger->post(CarbonImmutable::parse($p['counted_on']), ($difference > 0 ? 'Cash over' : 'Cash short')." in {$p['reference']}".(isset($p['note']) ? ": {$p['note']}" : ''), [
            ['account' => $debit, 'debit' => abs($difference)],
            ['account' => $credit, 'credit' => abs($difference)],
        ], $this->source($event, $p['reference']));
    }

    /**
     * A petty cash box Projects kept, moved into a Finance fund
     * (App\Finance\PettyCash): a petty cash account of its own, the fund with
     * no custodian until a manager names one after counting the cash, and what
     * the box held moved out of Cash, where Projects' till events had put it.
     * Its history (payments and receipts) stays in Projects.
     */
    private function pettyCashTransferred(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'reference' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'float_minor' => ['required', 'integer', 'min:1'],
            'held_minor' => ['required', 'integer', 'min:0'],
            'moved_on' => ['required', 'date_format:Y-m-d'],
            'custodian' => ['nullable', 'string', 'max:255'],
        ]);
        $p['currency'] = strtoupper((string) $p['currency']);
        $reference = "projects:petty-cash:{$p['reference']}";
        if (PettyCashFund::query()->where('source_reference', $reference)->exists()) {
            return;
        }

        $currency = $p['currency'] === Money::currency() ? null : (string) $p['currency'];
        $name = mb_substr((string) $p['name'], 0, 100);
        if (PettyCashFund::query()->where('name', $name)->exists()) {
            $name .= ' (Projects)';
        }

        $account = new Account([
            'code' => $this->freePettyCashCode(),
            'name' => mb_substr("Petty cash: {$name}", 0, 255),
            'type' => AccountType::Asset,
            'bank_kind' => BankKind::PettyCash,
            'currency' => $currency,
            'description' => 'Moved in from Projects.',
            'active' => true,
        ]);
        $account->save();

        $fund = PettyCashFund::query()->create([
            'name' => $name,
            'account_id' => $account->id,
            'custodian_id' => null,
            'float_minor' => (int) $p['float_minor'],
            'status' => PettyCashFund::ACTIVE,
            'created_by' => null,
            'source_reference' => $reference,
        ]);

        $held = (int) $p['held_minor'];
        $journal = null;
        if ($held > 0) {
            $on = CarbonImmutable::parse($p['moved_on']);
            $books = $this->inBooks($held, (string) $p['currency'], $on);
            $journal = $this->ledger->post($on, mb_substr("Petty cash {$name}: moved from Projects", 0, 255), [
                ['account' => $account, 'debit' => $books, 'currency' => $currency === null ? null : ['code' => $currency, 'amount' => $held]],
                ['account' => Account::system(Account::CASH), 'credit' => $books],
            ], $this->source($event, (string) $p['reference']));
        }

        app(Audit::class)->record('petty_cash.fund_moved_in', $fund, null, [
            'name' => $name,
            'account' => $account->code,
            'currency' => $currency,
            'float_minor' => $fund->float_minor,
            'held_minor' => $held,
            'custodian_in_projects' => $p['custodian'] ?? null,
            'journal' => $journal?->number,
        ]);
    }

    /**
     * The first free code from 1041 for a new petty cash account.
     */
    private function freePettyCashCode(): string
    {
        $taken = Account::query()->whereBetween('code', ['1041', '1099'])->pluck('code')->all();
        for ($code = 1041; $code <= 1099; $code++) {
            if (! in_array((string) $code, $taken, true)) {
                return (string) $code;
            }
        }

        throw new UnsupportedEvent('No free account code from 1041 to 1099 for a petty cash account.');
    }

    private function cashMoved(Envelope $event): void
    {
        $p = $event->payload;
        $amount = (int) $p['amount_minor'];
        if ($amount <= 0) {
            return;
        }

        $into = $p['direction'] === 'in';
        $counterpart = match ($p['counterpart']) {
            'bank' => Account::system(Account::BANK),
            'expense' => $into
                ? throw new UnsupportedEvent('Cash cannot come into a till from an expense.')
                : $this->expenseAccount($p['expense_account'] ?? null),
            default => throw new UnsupportedEvent("Finance does not know where cash moved to: {$p['counterpart']}."),
        };
        $cash = Account::system(Account::CASH);
        [$debit, $credit] = $into ? [$cash, $counterpart] : [$counterpart, $cash];

        $this->ledger->post(CarbonImmutable::parse($p['moved_on']), "{$p['description']} ({$p['reference']})", [
            ['account' => $debit, 'debit' => $amount, 'description' => $p['description']],
            ['account' => $credit, 'credit' => $amount],
        ], $this->source($event, $p['reference']));
    }

    /**
     * The active expense account the product named, or Other expenses
     * (by key, or by its starting code in charts made before the key).
     */
    private function expenseAccount(?string $code): Account
    {
        $named = $code === null ? null : Account::query()
            ->where('code', $code)
            ->where('type', AccountType::Expense)
            ->where('active', true)
            ->first();

        return $named
            ?? Account::query()->where('system_key', Account::OTHER_EXPENSES)->first()
            ?? Account::query()->where('code', '6900')->where('type', AccountType::Expense)->first()
            ?? Account::system(Account::OTHER_EXPENSES);
    }

    private function billReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::parse($p['received_on']);
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'expense_account');

        $supplier = isset($p['supplier_id'])
            ? Supplier::query()->firstOrCreate(
                ['source_app' => $event->sourceApp, 'source_supplier_id' => $p['supplier_id']],
                ['name' => $p['supplier_name']],
            )
            : Supplier::query()->firstOrCreate(['name' => $p['supplier_name'], 'source_app' => null]);

        $journalLines = [];
        foreach ($this->split($lines, $total - $tax, Account::INVENTORY) as [$account, $amount]) {
            $journalLines[] = ['account' => $account, 'debit' => $amount];
        }
        if ($tax > 0) {
            $journalLines[] = ['account' => Account::system(Account::TAX), 'debit' => $tax, 'tax' => ['kind' => JournalLine::INPUT, 'base' => $total - $tax]];
        }
        $journalLines[] = ['account' => Account::system(Account::PAYABLE), 'credit' => $total, 'description' => $supplier->name];

        $journal = $total > 0
            ? $this->ledger->post($date, "Bill {$p['bill_number']} from {$supplier->name}", $journalLines, $this->source($event, $p['bill_number']))
            : null;

        Bill::query()->create([
            'supplier_id' => $supplier->id,
            'number' => $p['bill_number'],
            'supplier_reference' => $p['supplier_reference'] ?? null,
            'source_app' => $event->sourceApp,
            'branch_id' => $p['branch_id'] ?? null,
            'bill_date' => $date,
            'due_on' => $p['due_on'] ?? null,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'lines' => $lines,
            'journal_id' => $journal?->id,
        ]);
    }

    /**
     * Goods sent back: Dr payable / Cr inventory (or the line's account),
     * and the credit set against the bill it came from.
     */
    private function creditNoteReceived(Envelope $event): void
    {
        $p = $event->payload;
        $date = CarbonImmutable::parse($p['credited_on']);
        $total = (int) $p['total_minor'];
        $tax = (int) ($p['tax_minor'] ?? 0);
        $lines = $this->lines($p['lines'] ?? [], 'expense_account');

        $bill = null;
        if (isset($p['bill_number'])) {
            $bill = Bill::query()->where('source_app', $event->sourceApp)->where('number', $p['bill_number'])->lockForUpdate()->first();

            if ($bill === null) {
                throw new NotYetPostable("Bill {$p['bill_number']} has not arrived yet.");
            }
        }

        $supplier = $bill?->supplier()->firstOrFail() ?? (isset($p['supplier_id'])
            ? Supplier::query()->firstOrCreate(
                ['source_app' => $event->sourceApp, 'source_supplier_id' => $p['supplier_id']],
                ['name' => $p['supplier_name']],
            )
            : Supplier::query()->firstOrCreate(['name' => $p['supplier_name'], 'source_app' => null]));

        $journal = null;
        if ($total > 0) {
            $journalLines = [['account' => Account::system(Account::PAYABLE), 'debit' => $total, 'description' => $supplier->name]];
            foreach ($this->split($lines, $total - $tax, Account::INVENTORY) as [$account, $amount]) {
                $journalLines[] = ['account' => $account, 'credit' => $amount];
            }
            if ($tax > 0) {
                $journalLines[] = ['account' => Account::system(Account::TAX), 'credit' => $tax, 'tax' => ['kind' => JournalLine::INPUT, 'base' => $tax - $total]];
            }

            $journal = $this->ledger->post($date, "Credit {$p['credit_note_number']} from {$supplier->name}: {$p['reason']}", $journalLines, $this->source($event, $p['credit_note_number']));
        }

        $applied = $bill === null ? 0 : min($total, $bill->outstanding());
        $bill?->forceFill(['paid_minor' => $bill->paid_minor + $applied])->save();

        SupplierCredit::query()->create([
            'supplier_id' => $supplier->id,
            'number' => $p['credit_note_number'],
            'source_app' => $event->sourceApp,
            'bill_id' => $bill?->id,
            'branch_id' => $p['branch_id'] ?? null,
            'credited_on' => $date,
            'total_minor' => $total,
            'tax_minor' => $tax,
            'applied_minor' => $applied,
            'reason' => $p['reason'],
            'lines' => $lines,
            'journal_id' => $journal?->id,
        ]);
    }

    /**
     * Which account takes a payment made this way.
     */
    /**
     * One summarised journal for an approved payroll run: totals only, never
     * one person's pay (the shared-module kit refuses pay keys).
     */
    private function payrollPosted(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'run_reference' => ['required', 'string', 'max:64'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d'],
            'earnings_minor' => ['required', 'integer', 'min:0'],
            'employer_contributions_minor' => ['required', 'integer', 'min:0'],
            'income_tax_minor' => ['required', 'integer', 'min:0'],
            'social_security_minor' => ['required', 'integer', 'min:0'],
            'other_deductions_minor' => ['required', 'integer', 'min:0'],
            'payable_minor' => ['required', 'integer', 'min:0'],
        ]);

        $debits = [
            [Account::SALARIES, (int) $p['earnings_minor']],
            [Account::EMPLOYER_CONTRIBUTIONS, (int) $p['employer_contributions_minor']],
        ];
        $credits = [
            [Account::PAYE, (int) $p['income_tax_minor']],
            [Account::SOCIAL_SECURITY_PAYABLE, (int) $p['social_security_minor']],
            [Account::PAYROLL_DEDUCTIONS, (int) $p['other_deductions_minor']],
            [Account::SALARIES_PAYABLE, (int) $p['payable_minor']],
        ];
        if (array_sum(array_column($debits, 1)) !== array_sum(array_column($credits, 1))) {
            throw ValidationException::withMessages(['payable_minor' => 'Earnings and employer contributions must equal what is withheld plus what is payable.']);
        }

        $lines = [];
        foreach ($debits as [$key, $amount]) {
            if ($amount > 0) {
                $lines[] = ['account' => $this->chart->payrollAccount($key), 'debit' => $amount];
            }
        }
        foreach ($credits as [$key, $amount]) {
            if ($amount > 0) {
                $lines[] = ['account' => $this->chart->payrollAccount($key), 'credit' => $amount];
            }
        }
        if ($lines === []) {
            return;
        }

        $date = CarbonImmutable::parse($p['period_end']);
        $this->ledger->post($date, "Payroll {$p['run_reference']} ({$date->format('F Y')})", $lines, $this->source($event, $p['run_reference']));
    }

    private function payrollPaid(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'run_reference' => ['required', 'string', 'max:64'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);
        if ((int) $p['amount_minor'] === 0) {
            return;
        }

        $this->ledger->post(CarbonImmutable::instance($event->occurredAt)->startOfDay(), "Net pay for payroll {$p['run_reference']}", [
            ['account' => $this->chart->payrollAccount(Account::SALARIES_PAYABLE), 'debit' => (int) $p['amount_minor']],
            ['account' => $this->moneyAccount(PaymentMethod::from($p['method'])), 'credit' => (int) $p['amount_minor']],
        ], $this->source($event, "{$p['run_reference']}-PAID"));
    }

    /**
     * Spending on a project paid (or any NGO spending: the project is
     * optional): Dr the category's account, tagged with the project, Cr where
     * the money came from. Paid from a staff advance, it comes out of
     * Employee advances.
     */
    private function projectExpensePaid(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'reference' => ['required', 'string', 'max:64'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(self::PROJECT_CATEGORIES))],
            'method' => ['required', Rule::in(self::PROJECT_METHODS)],
            'supplier' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'expense_account' => ['nullable', 'string', 'max:32'],
            'project' => ['nullable', 'array'],
            'project.code' => ['required_with:project', 'string', 'max:32'],
            'project.name' => ['nullable', 'string', 'max:255'],
        ]);

        $on = CarbonImmutable::parse($p['paid_on']);
        $amount = $this->inBooks((int) $p['amount_minor'], strtoupper($p['currency']), $on);
        $project = $this->projectDimension($p['project'] ?? null);
        $memo = ($project !== null ? "{$project->code}: " : '').$p['description'];

        $this->ledger->post($on, mb_substr($memo, 0, 255), [
            ['account' => $this->projectCostAccount($p['category'], $p['expense_account'] ?? null), 'debit' => $amount, 'description' => $p['supplier'] ?? null, 'project' => $project],
            ['account' => $this->projectMoneyAccount($p['method']), 'credit' => $amount, 'description' => $p['payment_reference'] ?? null, 'project' => $project],
        ], $this->source($event, $p['reference']));
    }

    /**
     * The payment taken back out: the mirror of its journal. A void that
     * arrives before its payment waits for it.
     */
    private function projectExpenseVoided(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'reference' => ['required', 'string', 'max:64'],
            'voided_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $journal = Journal::query()
            ->where('source_app', $event->sourceApp)
            ->where('source_type', 'finance.project_expense.paid')
            ->where('source_reference', $p['reference'])
            ->first();
        if ($journal === null) {
            throw new NotYetPostable("The payment {$p['reference']} has not reached Finance yet.");
        }
        if ($journal->reversed_by_id !== null) {
            return;
        }

        $this->ledger->reverse($journal, CarbonImmutable::parse($p['voided_on']), mb_substr("Void of {$journal->number}: {$p['reason']}", 0, 255), periods: Periods::MOVE);
    }

    /**
     * A grant payment received from a funder: Dr the bank (or where it was
     * paid into), Cr grant income.
     */
    private function grantReceived(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'reference' => ['required', 'string', 'max:64'],
            'grant_reference' => ['required', 'string', 'max:64'],
            'grant_title' => ['nullable', 'string', 'max:255'],
            'funder' => ['nullable', 'string', 'max:255'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['bank_transfer', 'cash', 'mobile_money'])],
        ]);

        $on = CarbonImmutable::parse($p['received_on']);
        $amount = $this->inBooks((int) $p['amount_minor'], strtoupper($p['currency']), $on);

        $this->ledger->post($on, mb_substr("Grant {$p['grant_reference']}: payment received".(isset($p['funder']) ? " from {$p['funder']}" : ''), 0, 255), [
            ['account' => $this->projectMoneyAccount($p['method'] ?? 'bank_transfer'), 'debit' => $amount],
            ['account' => $this->chart->onDemand(Account::GRANT_INCOME), 'credit' => $amount, 'description' => $p['grant_title'] ?? null],
        ], $this->source($event, $p['reference']));
    }

    /**
     * An advance paid out to a member of staff: an asset until they account
     * for it. Dr Employee advances, Cr where it was paid from.
     */
    private function staffAdvanceIssued(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'advance_reference' => ['required', 'string', 'max:64'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'mobile_money'])],
            'held_by' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);

        $on = CarbonImmutable::parse($p['issued_on']);
        $amount = $this->inBooks((int) $p['amount_minor'], strtoupper($p['currency']), $on);

        $this->ledger->post($on, mb_substr("Advance {$p['advance_reference']}".(isset($p['held_by']) ? " to {$p['held_by']}" : ''), 0, 255), [
            ['account' => $this->chart->advances(), 'debit' => $amount, 'description' => $p['purpose'] ?? null],
            ['account' => $this->projectMoneyAccount($p['method']), 'credit' => $amount],
        ], $this->source($event, $p['advance_reference']));
    }

    /**
     * What is left of an advance, settled without receipts: cash handed
     * back (Dr where it went, Cr Employee advances) or written off (Dr Staff
     * advances written off, Cr Employee advances). Receipts come as
     * finance.project_expense.paid with method staff_advance.
     */
    private function staffAdvanceSettled(Envelope $event): void
    {
        $p = Validator::validate($event->payload, [
            'reference' => ['required', 'string', 'max:64'],
            'advance_reference' => ['required', 'string', 'max:64'],
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['returned', 'written_off'])],
            'method' => ['required_if:kind,returned', 'nullable', Rule::in(['bank_transfer', 'cash', 'mobile_money'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $on = CarbonImmutable::parse($p['settled_on']);
        $amount = $this->inBooks((int) $p['amount_minor'], strtoupper($p['currency']), $on);
        [$debit, $label] = $p['kind'] === 'returned'
            ? [$this->projectMoneyAccount((string) $p['method']), 'returned']
            : [$this->chart->onDemand(Account::ADVANCES_WRITTEN_OFF), 'written off'];

        $this->ledger->post($on, "Advance {$p['advance_reference']}: {$label}", [
            ['account' => $debit, 'debit' => $amount, 'description' => $p['note'] ?? null],
            ['account' => $this->chart->advances(), 'credit' => $amount],
        ], $this->source($event, $p['reference']));
    }

    /** Projects' cost categories and the accounts they post to. */
    private const PROJECT_CATEGORIES = [
        'labour' => Account::SALARIES,
        'materials' => Account::PROJECT_MATERIALS,
        'equipment' => Account::PROJECT_EQUIPMENT,
        'travel' => Account::PROJECT_TRAVEL,
        'subcontract' => Account::PROJECT_SUBCONTRACT,
        'other' => Account::OTHER_EXPENSES,
    ];

    private const PROJECT_METHODS = ['bank_transfer', 'cash', 'mobile_money', 'staff_advance'];

    /**
     * The account the product named (an active expense or asset account),
     * else the category's.
     */
    private function projectCostAccount(string $category, ?string $code): Account
    {
        $named = $code === null ? null : Account::query()
            ->where('code', $code)
            ->whereIn('type', [AccountType::Expense, AccountType::Asset])
            ->where('active', true)
            ->first();

        return $named ?? $this->chart->onDemand(self::PROJECT_CATEGORIES[$category]);
    }

    private function projectMoneyAccount(string $method): Account
    {
        return $method === 'staff_advance'
            ? $this->chart->advances()
            : $this->moneyAccount(PaymentMethod::from($method));
    }

    /**
     * The project as an accounting dimension, made on first use (or the one
     * the accountant already made under that code).
     *
     * @param  array<string, mixed>|null  $project
     */
    private function projectDimension(?array $project): ?DimensionValue
    {
        $code = mb_substr(strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($project['code'] ?? ''))), 0, 16);
        if ($code === '') {
            return null;
        }

        return DimensionValue::query()->firstOrCreate(
            ['kind' => DimensionValue::PROJECT, 'code' => $code],
            ['name' => mb_substr((string) ($project['name'] ?? $code), 0, 255), 'active' => true],
        );
    }

    /**
     * An amount in the books' currency, at Finance's rate for the day. With
     * no rate yet the event waits: the product retries once one is added.
     */
    private function inBooks(int $minor, string $currency, CarbonImmutable $on): int
    {
        if ($currency === Money::currency()) {
            return $minor;
        }

        try {
            $rate = app(Currencies::class)->rate($currency, $on);
        } catch (ValidationException) {
            throw new NotYetPostable("No {$currency} rate on or before {$on->toDateString()}: add one under Currencies.");
        }

        return Currencies::toBooks($minor, $currency, $rate);
    }

    public function moneyAccount(PaymentMethod $method): Account
    {
        return Account::system(match ($method) {
            PaymentMethod::MobileMoney => Account::MOBILE_MONEY,
            PaymentMethod::Card => Account::CARD,
            PaymentMethod::BankTransfer => Account::BANK,
            PaymentMethod::Cash, PaymentMethod::Other => Account::CASH,
        });
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}>
     */
    private function lines(array $lines, string $accountKey): array
    {
        return array_values(array_map(fn ($line) => [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $line['quantity'] ?? null,
            'amount_minor' => (int) ($line['amount_minor'] ?? 0),
            'account' => isset($line[$accountKey]) ? (string) $line[$accountKey] : null,
            'cost_minor' => isset($line['cost_minor']) ? max(0, (int) $line['cost_minor']) : null,
        ], array_filter($lines, 'is_array')));
    }

    /**
     * The amount split across the accounts the lines name (by code), the
     * rest of it (or all of it, when the lines do not add up) to the default.
     *
     * @param  list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}>  $lines
     * @return list<array{0: Account, 1: int}>
     */
    private function split(array $lines, int $amount, string $defaultKey): array
    {
        $named = [];
        if (array_sum(array_column($lines, 'amount_minor')) === $amount) {
            foreach ($lines as $line) {
                $account = $line['account'] === null ? null : Account::query()->where('code', $line['account'])->where('active', true)->first();
                if ($account !== null) {
                    $named[$account->id] ??= [$account, 0];
                    $named[$account->id][1] += $line['amount_minor'];
                }
            }
        }

        $rest = $amount - array_sum(array_column($named, 1));
        $split = array_values(array_filter($named, fn (array $part) => $part[1] > 0));
        if ($rest > 0) {
            array_unshift($split, [Account::system($defaultKey), $rest]);
        }

        return $split;
    }

    /**
     * @return array{source_app: string, source_type: string, source_reference: string, branch_id: string|null}
     */
    private function source(Envelope $event, string $reference): array
    {
        return [
            'source_app' => $event->sourceApp,
            'source_type' => $event->eventType,
            'source_reference' => $reference,
            'branch_id' => $event->payload['branch_id'] ?? null,
            // A product's event is never refused for a locked month.
            'periods' => Periods::MOVE,
        ];
    }

    private function refreshPaid(Invoice $invoice): void
    {
        $paid = (int) CustomerPayment::query()->where('invoice_id', $invoice->id)->get()->sum(fn (CustomerPayment $payment) => $payment->signedAmount());
        $invoice->forceFill(['paid_minor' => $paid])->save();
    }
}
