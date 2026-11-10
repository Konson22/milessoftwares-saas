<?php

namespace App\Finance;

use App\Enums\BankKind;
use App\Models\Account;
use App\Models\Bill;
use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Models\Supplier;
use App\Models\TaxReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Prepare, (review,) approve, disburse. A request moves no balance until it
 * is disbursed, when it posts through Payables or Banking: there is one
 * ledger, and an approved payment reaches the books like any other.
 *
 * Each step is checked here, whichever route called it: a request cannot be
 * approved before its review or disbursed before its approval, and one person
 * never acts twice on it (refusal()). Owners are held to this too. A request
 * is never edited: to change what is paid, withdraw it and raise another.
 *
 * Besides what is asked for on the form, anything else that would take money
 * out of a bank from the approval limit up waits here too: a tax settlement,
 * a manual journal, a journal's reversal.
 */
class PaymentRequests
{
    /**
     * The permissions that raise requests: the form, journals that take money
     * out of a bank (prepared, posted or reversed) and tax settlements.
     */
    public const RAISED_BY = 'finance.payments.prepare,finance.journals.prepare,finance.journals.post,finance.journals.reverse,finance.tax.manage,finance.pettycash.record,finance.pettycash.manage';

    public function __construct(
        private readonly PaymentPolicy $policy,
        private readonly Payables $payables,
        private readonly Banking $banking,
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumbers $numbers,
        private readonly Ledger $ledger,
        private readonly Tax $tax,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{payee?: string|null, other_account?: Account|null, supplier?: Supplier|null, cost_centre?: DimensionValue|null, project?: DimensionValue|null, currency?: string|null, reference?: string|null, rate?: string|null, allocations?: array<string, int>, received_minor?: int|null}  $extra
     *
     * @throws ValidationException
     */
    public function submit(string $kind, Account $from, int $amountMinor, CarbonImmutable $payOn, string $description, User $by, array $extra = []): PaymentRequest
    {
        if (! $from->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Pay from a bank or cash account.']);
        }
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }

        $supplier = $extra['supplier'] ?? null;
        $allocations = array_filter($extra['allocations'] ?? [], fn (int $amount) => $amount > 0);
        if ($kind === PaymentRequest::SUPPLIER) {
            $this->checkBills($supplier, $amountMinor, $allocations);
        }

        if ($kind === PaymentRequest::TRANSFER) {
            $this->checkTransfer($from, $extra['other_account'] ?? null, $extra['received_minor'] ?? null);
        }

        $currency = $extra['currency'] ?? $from->currency;
        // A transfer posts at the day's rates, so a typed one would decide nothing but its approval.
        $rate = $kind === PaymentRequest::TRANSFER ? null : ($extra['rate'] ?? null);
        $needed = $this->policy->approvalNeeded($amountMinor, $currency, $payOn, $rate);

        return $this->record($kind, $from, $amountMinor, $currency, $this->policy->booksAmount($amountMinor, $currency, $payOn, $rate), $payOn, $description, $by, [
            'allocations' => $allocations,
            'reference' => $extra['reference'] ?? null,
            'rate' => $rate,
            'received_minor' => $extra['received_minor'] ?? null,
        ], $needed, [
            'payee' => $extra['payee'] ?? $supplier?->name,
            'other_account_id' => ($extra['other_account'] ?? null)?->id,
            'supplier_id' => $supplier?->id,
            'cost_centre_id' => ($extra['cost_centre'] ?? null)?->id,
            'project_id' => ($extra['project'] ?? null)?->id,
        ]);
    }

    /**
     * Money for a petty cash fund (AppFinancePettyCash::replenish): a
     * transfer from $bank, approved and disbursed like any other. The
     * vouchers it pays back are marked when it is disbursed.
     *
     * @param  list<string>  $voucherIds
     *
     * @throws ValidationException when one is already on its way
     */
    public function replenish(PettyCashFund $fund, Account $bank, Account $box, int $amountMinor, CarbonImmutable $payOn, array $voucherIds, User $by): PaymentRequest
    {
        if (($open = $this->openFor(PaymentRequest::TRANSFER, 'petty_cash_fund_id', $fund->id)) !== null) {
            throw ValidationException::withMessages(['fund' => "{$open->number} is already on its way to {$fund->name}."]);
        }

        return $this->record(PaymentRequest::TRANSFER, $bank, $amountMinor, $bank->currency, $this->policy->booksAmount($amountMinor, $bank->currency, $payOn), $payOn, "Petty cash {$fund->name}: ".($voucherIds === [] ? 'float' : 'replenishment'), $by, [
            'received_minor' => null,
            'petty_cash_fund_id' => $fund->id,
            'voucher_ids' => $voucherIds,
        ], $this->policy->approvalNeeded($amountMinor, $bank->currency, $payOn, null), [
            'payee' => $fund->name,
            'other_account_id' => $box->id,
        ]);
    }

    /**
     * Holds the settlement of what a tax return owes.
     *
     * @throws ValidationException when it is settled or already on its way
     */
    public function holdTax(TaxReturn $return, Account $bank, CarbonImmutable $on, User $by): PaymentRequest
    {
        if (! $bank->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Settle from a bank or cash account.']);
        }
        if ($return->settlement_journal_id !== null) {
            throw ValidationException::withMessages(['return' => 'This return is already settled.']);
        }
        $open = $this->openFor(PaymentRequest::TAX, 'tax_return_id', $return->id);
        if ($open !== null) {
            throw ValidationException::withMessages(['return' => "{$open->number} is already waiting to settle this return."]);
        }

        $amount = abs($return->net_minor);
        $description = 'Tax for '.$return->starts_on->format('j M Y').' to '.$return->ends_on->format('j M Y');

        return $this->record(PaymentRequest::TAX, $bank, $amount, null, $amount, $on, $description, $by, ['tax_return_id' => $return->id]);
    }

    /**
     * Holds a manual journal that takes money out of a bank. $draft is the
     * journal approval it already passed, if it needed one.
     *
     * @param  list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>  $lines
     *
     * @throws ValidationException when the lines could never post
     */
    public function holdJournal(CarbonImmutable $on, string $memo, array $lines, User $by, ?string $draft = null): PaymentRequest
    {
        $this->ledger->check(StoredLines::toLedger($lines), requireDimensions: true);

        $banks = $this->policy->bankIds(array_column($lines, 'account_id'));
        $paid = array_filter($lines, fn (array $line) => in_array($line['account_id'], $banks, true) && (int) $line['credit_minor'] > 0);
        usort($paid, fn (array $a, array $b) => (int) $b['credit_minor'] <=> (int) $a['credit_minor']);
        $amount = $this->policy->bankOutflow($lines);

        return $this->record(PaymentRequest::JOURNAL, Account::query()->findOrFail($paid[0]['account_id']), $amount, null, $amount, $on, $memo, $by, ['lines' => $lines, 'draft' => $draft]);
    }

    /**
     * How much reversing this journal would take out of a bank: what it put in.
     */
    public function reversalOutflow(Journal $journal): int
    {
        return (int) $journal->lines()->whereIn('account_id', $this->policy->bankIds())->sum('debit_minor');
    }

    /**
     * Holds the reversal of a journal that brought money into a bank.
     *
     * @throws ValidationException when it is reversed or already on its way
     */
    public function holdReversal(Journal $journal, CarbonImmutable $on, string $memo, User $by): PaymentRequest
    {
        if ($journal->reversed_by_id !== null) {
            throw ValidationException::withMessages(['journal' => "{$journal->number} is already reversed."]);
        }
        if ($journal->reversal_of_id !== null) {
            throw ValidationException::withMessages(['journal' => "{$journal->number} is a reversal; post a new journal instead."]);
        }
        $open = $this->openFor(PaymentRequest::REVERSAL, 'journal_id', $journal->id);
        if ($open !== null) {
            throw ValidationException::withMessages(['journal' => "{$open->number} is already waiting to reverse {$journal->number}."]);
        }

        $line = $journal->lines()->whereIn('account_id', $this->policy->bankIds())->where('debit_minor', '>', 0)->orderByDesc('debit_minor')->firstOrFail();
        $amount = $this->reversalOutflow($journal);

        return $this->record(PaymentRequest::REVERSAL, Account::query()->findOrFail($line->account_id), $amount, null, $amount, $on, $memo, $by, ['journal_id' => $journal->id, 'memo' => $memo]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $kind, Account $from, int $amountMinor, ?string $currency, int $booksMinor, CarbonImmutable $payOn, string $description, User $by, array $details, bool $needsApproval = true, array $attributes = []): PaymentRequest
    {
        $status = match (true) {
            ! $needsApproval => PaymentRequest::APPROVED,
            $this->policy->reviewsFirst() => PaymentRequest::PENDING_REVIEW,
            default => PaymentRequest::PENDING_APPROVAL,
        };

        return DB::transaction(function () use ($attributes, $kind, $status, $description, $from, $amountMinor, $currency, $booksMinor, $payOn, $details, $by) {
            $request = PaymentRequest::query()->create($attributes + [
                'number' => $this->numbers->next('payment_request', 'PR', 5),
                'kind' => $kind,
                'status' => $status,
                'description' => mb_substr($description, 0, 255),
                'account_id' => $from->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'books_minor' => $booksMinor,
                'pay_on' => $payOn,
                'details' => $details,
                'prepared_by' => $by->id,
                'policy' => $this->policy->snapshot(),
            ]);
            $this->audit->record('payment_request.prepared', $request, null, $this->terms($request) + ['status' => $status], $by);

            return $request;
        });
    }

    private function openFor(string $kind, string $key, string $value): ?PaymentRequest
    {
        return PaymentRequest::query()->where('kind', $kind)->whereIn('status', PaymentRequest::OPEN)->get()
            ->first(fn (PaymentRequest $request) => ($request->details[$key] ?? null) === $value);
    }

    /**
     * @throws ValidationException when it is not waiting for review, or is the reviewer's own
     */
    public function review(PaymentRequest $request, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = $this->locked($request);
            if ($locked->status !== PaymentRequest::PENDING_REVIEW) {
                throw $this->refused("{$locked->number} is not waiting for review.");
            }
            if (($why = self::refusal($locked, 'review', $by->id)) !== null) {
                throw $this->refused($why);
            }

            $locked->forceFill(['status' => PaymentRequest::PENDING_APPROVAL, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
            $this->audit->record('payment_request.reviewed', $locked, ['status' => PaymentRequest::PENDING_REVIEW], ['status' => $locked->status], $by);

            return $locked;
        });
    }

    /**
     * @throws ValidationException when it is not waiting for approval, or the approver prepared or reviewed it
     */
    public function approve(PaymentRequest $request, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = $this->locked($request);
            if ($locked->status === PaymentRequest::PENDING_REVIEW) {
                throw $this->refused("{$locked->number} has to be reviewed first.");
            }
            if ($locked->status !== PaymentRequest::PENDING_APPROVAL) {
                throw $this->refused("{$locked->number} is not waiting for approval.");
            }
            if (($why = self::refusal($locked, 'approve', $by->id)) !== null) {
                throw $this->refused($why);
            }

            $locked->forceFill(['status' => PaymentRequest::APPROVED, 'approved_by' => $by->id, 'approved_at' => now()])->save();
            $this->audit->record('payment_request.approved', $locked, ['status' => PaymentRequest::PENDING_APPROVAL], $this->terms($locked) + ['status' => $locked->status], $by);

            return $locked;
        });
    }

    /**
     * @throws ValidationException when it is already closed, or is the rejecter's own (withdraw it instead)
     */
    public function reject(PaymentRequest $request, string $reason, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $reason, $by) {
            $locked = $this->open($request);
            if ($locked->prepared_by === $by->id) {
                throw $this->refused("{$locked->number} is your own request: withdraw it instead.");
            }

            return $this->close($locked, PaymentRequest::REJECTED, $by, $reason);
        });
    }

    /**
     * Takes back one's own request before it is paid.
     *
     * @throws ValidationException
     */
    public function withdraw(PaymentRequest $request, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = $this->open($request);
            if ($locked->prepared_by !== $by->id) {
                throw $this->refused("Only the person who prepared {$locked->number} can withdraw it.");
            }

            return $this->close($locked, PaymentRequest::WITHDRAWN, $by);
        });
    }

    /**
     * Pays it: the money leaves and the ledger is posted, dated $on. A refusal
     * (a locked month, a bill already paid) leaves it approved.
     *
     * @throws ValidationException
     */
    public function disburse(PaymentRequest $request, CarbonImmutable $on, ?string $reference, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $on, $reference, $by) {
            $locked = $this->locked($request);
            if (in_array($locked->status, [PaymentRequest::PENDING_REVIEW, PaymentRequest::PENDING_APPROVAL], true)) {
                throw $this->refused("{$locked->number} has not been approved.");
            }
            if ($locked->status !== PaymentRequest::APPROVED) {
                throw $this->refused("{$locked->number} is no longer waiting to be paid.");
            }
            if (($why = self::refusal($locked, 'disburse', $by->id)) !== null) {
                throw $this->refused($why);
            }

            $paid = $this->pay($locked, $on, $reference ?? ($locked->details['reference'] ?? null), $by);
            if (is_string($locked->details['petty_cash_fund_id'] ?? null)) {
                PettyCashVoucher::query()->where('fund_id', $locked->details['petty_cash_fund_id'])->whereIn('id', $locked->details['voucher_ids'] ?? [])->whereNull('replenished_by_id')->update(['replenished_by_id' => $locked->id]);
            }
            if ($locked->approved_by !== null) {
                $paid['journal']->forceFill(['approved_by' => $locked->approved_by, 'approved_at' => $locked->approved_at])->save();
            }

            $locked->forceFill([
                'status' => PaymentRequest::DISBURSED,
                'disbursed_by' => $by->id,
                'disbursed_at' => now(),
                'journal_id' => $paid['journal']->id,
                'supplier_payment_id' => $paid['supplierPaymentId'],
            ])->save();
            $this->audit->record('payment_request.disbursed', $locked, ['status' => PaymentRequest::APPROVED], $this->terms($locked) + ['status' => $locked->status, 'journal_id' => $locked->journal_id, 'paid_on' => $on->toDateString()], $by);

            return $locked;
        });
    }

    /**
     * @return array{journal: Journal, supplierPaymentId: string|null}
     */
    private function pay(PaymentRequest $request, CarbonImmutable $on, ?string $reference, User $by): array
    {
        $from = Account::query()->findOrFail($request->account_id);
        if (! in_array($from->bank_kind, [BankKind::Cash, BankKind::PettyCash], true)) {
            return $this->post($request, $from, $on, $reference, $by);
        }

        // Cash cannot go below nothing (a bank may, on an overdraft). Locked,
        // so two payments out of one box cannot both see the same cash.
        $from = Account::query()->whereKey($from->id)->lockForUpdate()->firstOrFail();
        $paid = $this->post($request, $from, $on, $reference, $by);
        if (($held = $from->heldMinor()) < 0) {
            throw $this->refused("{$from->name} does not hold enough cash for {$request->number}; it stays ready to disburse.");
        }

        return $paid;
    }

    /**
     * @return array{journal: Journal, supplierPaymentId: string|null}
     */
    private function post(PaymentRequest $request, Account $from, CarbonImmutable $on, ?string $reference, User $by): array
    {
        // A journal's memo holds 255 characters, and the number must survive.
        $suffix = " ({$request->number})";
        $description = mb_substr($request->description, 0, 255 - mb_strlen($suffix)).$suffix;
        $rate = $request->details['rate'] ?? null;

        if ($request->kind === PaymentRequest::TAX) {
            $return = TaxReturn::query()->find($request->details['tax_return_id'] ?? null)
                ?? throw $this->refused("The tax return {$request->number} settles is no longer filed: reject this request.");
            $settled = $this->tax->settle($return, $from, $on, $by);

            return ['journal' => Journal::query()->findOrFail($settled->settlement_journal_id), 'supplierPaymentId' => null];
        }

        if ($request->kind === PaymentRequest::JOURNAL) {
            return ['journal' => $this->ledger->post($on, $description, StoredLines::toLedger($request->details['lines'] ?? []), [
                'require_dimensions' => true,
                'source_reference' => $request->details['draft'] ?? $request->number,
            ], $by), 'supplierPaymentId' => null];
        }

        if ($request->kind === PaymentRequest::REVERSAL) {
            $journal = Journal::query()->findOrFail($request->details['journal_id'] ?? null);

            return ['journal' => $this->ledger->reverse($journal, $on, mb_substr((string) ($request->details['memo'] ?? ''), 0, 255 - mb_strlen($suffix)).$suffix, $by), 'supplierPaymentId' => null];
        }

        if ($request->kind === PaymentRequest::SUPPLIER) {
            $supplier = Supplier::query()->findOrFail($request->supplier_id);
            $payment = $this->payables->pay($supplier, $from, $request->amount_minor, $on, $request->details['allocations'] ?? [], $reference, $description, $by, $rate);

            return ['journal' => Journal::query()->findOrFail($payment->journal_id), 'supplierPaymentId' => $payment->id];
        }

        if ($request->kind === PaymentRequest::TRANSFER) {
            $to = Account::query()->findOrFail($request->other_account_id);

            return ['journal' => $this->banking->transfer($from, $to, $request->amount_minor, $on, $description, $by, $request->details['received_minor'] ?? null), 'supplierPaymentId' => null];
        }

        $other = $request->kind === PaymentRequest::ADVANCE
            ? $this->chart->advances()
            : Account::query()->findOrFail($request->other_account_id);
        $dimensions = [
            'cost_centre' => $request->cost_centre_id === null ? null : DimensionValue::query()->find($request->cost_centre_id),
            'project' => $request->project_id === null ? null : DimensionValue::query()->find($request->project_id),
        ];

        return ['journal' => $this->banking->moneyOut($from, $other, $request->amount_minor, $on, $description, $by, $rate, $dimensions), 'supplierPaymentId' => null];
    }

    /**
     * Between currencies a transfer cannot post without what arrived, so it
     * is asked for now rather than after it has been approved.
     *
     * @throws ValidationException
     */
    private function checkTransfer(Account $from, ?Account $to, ?int $receivedMinor): void
    {
        if ($to !== null && $from->currency !== $to->currency && ($receivedMinor === null || $receivedMinor <= 0)) {
            throw ValidationException::withMessages(['received' => 'Say how much arrives in '.($to->currency ?? Money::currency()).'.']);
        }
    }

    /**
     * What can be told now; the rest is checked again when it is paid.
     *
     * @param  array<string, int>  $allocations
     */
    private function checkBills(?Supplier $supplier, int $amountMinor, array $allocations): void
    {
        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose a supplier.']);
        }
        if (array_sum($allocations) > $amountMinor) {
            throw ValidationException::withMessages(['allocations' => 'More is set against bills than was paid.']);
        }

        $bills = Bill::query()->whereIn('id', array_keys($allocations))->get()->keyBy('id');
        foreach ($allocations as $billId => $amount) {
            $bill = $bills->get($billId);
            if ($bill === null || $bill->supplier_id !== $supplier->id) {
                throw ValidationException::withMessages(['allocations' => 'A bill is not this supplier\'s.']);
            }
            if ($amount > $bill->currencyOutstanding()) {
                throw ValidationException::withMessages(['allocations' => "Bill {$bill->number} has only ".Money::toMajor($bill->currencyOutstanding(), $bill->currency).' outstanding.']);
            }
        }
    }

    private function open(PaymentRequest $request): PaymentRequest
    {
        $locked = $this->locked($request);
        if (! in_array($locked->status, PaymentRequest::OPEN, true)) {
            throw $this->refused("{$locked->number} is already closed.");
        }

        return $locked;
    }

    private function close(PaymentRequest $locked, string $status, User $by, ?string $note = null): PaymentRequest
    {
        $before = $locked->status;
        $locked->forceFill(['status' => $status, 'closed_by' => $by->id, 'closed_at' => now(), 'close_note' => $note])->save();
        $this->audit->record("payment_request.{$status}", $locked, ['status' => $before], ['status' => $status, 'note' => $note], $by);

        return $locked;
    }

    /**
     * Why this member may not take this step on this request, or null when
     * nothing about them stands in the way (their permission is checked on the
     * route). Not a setting, and owners are held to it: nobody reviews or
     * approves their own request, approves what they reviewed, or pays out a
     * request they prepared, reviewed or approved. A request below the
     * approval limit was never approved by anyone, so whoever prepared it may
     * also pay it out.
     */
    public static function refusal(PaymentRequest $request, string $step, string $userId): ?string
    {
        $number = $request->number;

        return match ($step) {
            'review' => $request->prepared_by === $userId ? "{$number} is your own request: someone else reviews it." : null,
            'approve' => match (true) {
                $request->prepared_by === $userId => "{$number} is your own request: someone else approves it.",
                $request->reviewed_by === $userId => "You reviewed {$number}: someone else approves it.",
                default => null,
            },
            'disburse' => match (true) {
                $request->approved_by === null => null,
                $request->approved_by === $userId => "You approved {$number}: someone else disburses it.",
                $request->prepared_by === $userId => "{$number} is your own request: someone else disburses it.",
                $request->reviewed_by === $userId => "You reviewed {$number}: someone else disburses it.",
                default => null,
            },
            default => throw new InvalidArgumentException("Not a step: {$step}"),
        };
    }

    /**
     * What was approved and paid: who, how much, from where, to whom.
     *
     * @return array<string, mixed>
     */
    private function terms(PaymentRequest $request): array
    {
        return [
            'kind' => $request->kind,
            'amount_minor' => $request->amount_minor,
            'currency' => $request->currency,
            'books_minor' => $request->books_minor,
            'account_id' => $request->account_id,
            'other_account_id' => $request->other_account_id,
            'supplier_id' => $request->supplier_id,
            'payee' => $request->payee,
            'prepared_by' => $request->prepared_by,
            'reviewed_by' => $request->reviewed_by,
            'approved_by' => $request->approved_by,
        ];
    }

    private function locked(PaymentRequest $request): PaymentRequest
    {
        return PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function refused(string $message): ValidationException
    {
        return ValidationException::withMessages(['request' => $message]);
    }
}
