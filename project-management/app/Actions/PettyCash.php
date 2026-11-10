<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\Organization;
use App\Models\PettyCashCount;
use App\Models\PettyCashFund;
use App\Models\PettyCashPayment;
use App\Models\PettyCashTopUp;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\FinanceEvents;
use App\Support\FinanceLink;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The rules of petty cash, as a fixed float:
 * - A box has a set amount and a custodian. What it holds is worked out
 *   here: the float, plus approved top-ups, less payments not voided, plus
 *   what counts found over (less what they found short).
 * - Finance keeps the books, and is told (finance.* events, in the same
 *   transaction):
 *     set up, top-up   cash.moved in from the bank
 *     payment          project_expense.paid, paid in cash
 *     void             project_expense.voided
 *     count            cash.counted, when it differs
 *     close            cash.moved out to the bank
 * - Only the custodian pays from a box, with a receipt, up to the limit the
 *   organization set. With no limit set, nothing is paid.
 * - A payment charged to a project is that project's spending: it needs an
 *   approved budget with enough left in the category, as any approval does.
 * - A top-up restores the set amount. It lists the payments it covers, and is
 *   decided by someone who is neither the custodian nor whoever asked. A
 *   covered payment no longer changes; until then one can be voided.
 * - Cash is counted by someone other than the custodian.
 * Every step runs in a transaction on the locked box and is audited.
 */
final class PettyCash
{
    public const DISK = Spending::DISK;

    public function __construct(
        private readonly ProjectCharge $projectCharge,
        private readonly ProjectBooks $books,
        private readonly FinanceEvents $events,
        private readonly FinanceLink $finance,
    ) {}

    /**
     * Whether Finance keeps this organization's petty cash (then nothing is
     * done to a box here but moving it there).
     */
    public function keptInFinance(): bool
    {
        return $this->finance->keepsPettyCash($this->organization());
    }

    /**
     * Hands a box to Finance, which opens a petty cash fund for it at the
     * same float, holding what the box holds, with no custodian until its
     * managers name one (finance.petty_cash.transferred). The cash does not
     * move, so no cash.moved is sent; the box stays here, read only, with
     * its payments and receipts. Project spending already paid stays charged.
     *
     * @throws ValidationException
     */
    public function moveToFinance(PettyCashFund $fund, CarbonImmutable $on, ?User $by): void
    {
        DB::transaction(function () use ($fund, $on, $by) {
            $locked = $this->lock($fund);
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['fund' => $locked->isMoved() ? "{$locked->name} is already in Finance." : "{$locked->name} is closed."]);
            }
            if ($locked->topUps()->where('status', PettyCashTopUp::SUBMITTED)->exists()) {
                throw ValidationException::withMessages(['fund' => 'A top-up is waiting for a decision: decide it first.']);
            }
            $held = $this->balance($locked);
            if ($held < 0) {
                throw ValidationException::withMessages(['fund' => 'The box is overdrawn: count it first.']);
            }

            $this->events->send('finance.petty_cash.transferred', "petty-cash:{$locked->id}:transferred", [
                'reference' => $locked->id,
                'name' => $locked->name,
                'currency' => $locked->currency,
                'float_minor' => $locked->float_minor,
                'held_minor' => $held,
                'moved_on' => $on->toDateString(),
                'custodian' => $locked->custodian->name,
            ], $on);
            $locked->forceFill(['status' => PettyCashFund::MOVED, 'moved_at' => now()])->save();
            Audit::record('petty_cash.fund_moved_to_finance', $locked, ['name' => $locked->name, 'held_minor' => $held, 'float_minor' => $locked->float_minor], actor: $by);
        });
    }

    /**
     * The largest single payment, in the organization's currency (null: none set).
     */
    public function setLimit(?int $amountMinor, User $by): void
    {
        $organization = $this->organization();
        $before = ['petty_cash_max_payment_minor' => $organization->petty_cash_max_payment_minor];
        $organization->forceFill(['petty_cash_max_payment_minor' => $amountMinor])->save();
        Audit::change('petty_cash.limit_changed', $organization, $before, ['petty_cash_max_payment_minor' => $amountMinor], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public function createFund(string $name, User $custodian, int $floatMinor, CarbonImmutable $on, User $by): PettyCashFund
    {
        $this->requireOwnPettyCash();
        if ($floatMinor <= 0) {
            throw ValidationException::withMessages(['float' => 'More than zero.']);
        }

        return DB::transaction(function () use ($name, $custodian, $floatMinor, $on, $by) {
            /** @var PettyCashFund $fund */
            $fund = PettyCashFund::query()->create([
                'name' => $name,
                'custodian_id' => $custodian->id,
                'float_minor' => $floatMinor,
                'currency' => Money::currency(),
                'status' => PettyCashFund::ACTIVE,
                'created_by' => $by->id,
            ]);

            $this->moved($fund, "petty-cash:{$fund->id}:setup", 'in', $floatMinor, $on, "Petty cash {$name}: set up", $by);
            Audit::record('petty_cash.fund_created', $fund, ['name' => $name, 'custodian' => $custodian->id, 'float_minor' => $floatMinor], actor: $by);

            return $fund;
        });
    }

    /**
     * @param  array{paid_on: CarbonImmutable, amount_minor: int, description: string, payee: string|null, category: CostCategory|null, project: Project|null}  $details
     *
     * @throws ValidationException
     */
    public function pay(PettyCashFund $fund, array $details, UploadedFile $receipt, User $by): PettyCashPayment
    {
        $category = $details['category'];
        if ($category === null) {
            throw ValidationException::withMessages(['category' => 'Choose what it was spent on.']);
        }

        $path = $receipt->store("organizations/{$fund->organization_id}/petty-cash/{$fund->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['receipt' => 'The receipt could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($fund, $details, $category, $receipt, $path, $by) {
                $locked = $this->lock($fund);
                $this->requireActive($locked);
                $this->requireCustodian($locked, $by);

                $amount = $details['amount_minor'];
                $this->requireWithinLimit($amount);
                $balance = $this->balance($locked);
                if ($amount > $balance) {
                    throw ValidationException::withMessages(['amount' => 'The box holds '.Money::format($balance, $locked->currency).'.']);
                }

                /** @var PettyCashPayment $payment */
                $payment = $locked->payments()->create([
                    'paid_on' => $details['paid_on']->toDateString(),
                    'amount_minor' => $amount,
                    'description' => $details['description'],
                    'payee' => $details['payee'],
                    'category' => $category,
                    'project_id' => $details['project']?->id,
                    'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 255),
                    'receipt_path' => $path,
                    'receipt_mime' => $receipt->getMimeType() ?? 'application/octet-stream',
                    'receipt_size' => (int) $receipt->getSize(),
                    'recorded_by' => $by->id,
                ]);

                if ($details['project'] !== null) {
                    $this->projectCharge->charge($details['project'], $category, [
                        'description' => $payment->description,
                        'payee' => $payment->payee,
                        'amount_minor' => $amount,
                        'spent_on' => $payment->paid_on,
                        'currency' => $locked->currency,
                        'reference' => "Petty cash: {$locked->name}",
                        'receipt' => ['name' => $payment->receipt_name, 'path' => $payment->receipt_path, 'mime' => $payment->receipt_mime, 'size' => $payment->receipt_size],
                    ], $by, fn (string $expenseId) => $payment->forceFill(['expense_id' => $expenseId])->save());
                }

                $this->books->spent(self::paymentReference($payment), [
                    'paid_on' => $details['paid_on'],
                    'currency' => $locked->currency,
                    'amount_minor' => $amount,
                    'description' => "Petty cash {$locked->name}: {$payment->description}",
                    'category' => $category,
                    'method' => ProjectBooks::CASH,
                    'supplier' => $payment->payee,
                    'project' => $details['project'],
                ]);
                Audit::record('petty_cash.paid', $payment, ['fund' => $locked->name, 'amount_minor' => $amount, 'description' => $payment->description], actor: $by);

                return $payment;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    /**
     * Takes a payment back. The cash is back in the box.
     *
     * @throws ValidationException
     */
    public function void(PettyCashPayment $payment, string $reason, User $by): void
    {
        DB::transaction(function () use ($payment, $reason, $by) {
            $fund = $this->lockById($payment->fund_id);
            $this->requireActive($fund);
            $payment->refresh();
            if ($payment->isVoid()) {
                throw ValidationException::withMessages(['payment' => 'It is already void.']);
            }
            if ($payment->top_up_id !== null) {
                throw ValidationException::withMessages(['payment' => 'A top-up covers it: it stays.']);
            }

            $payment->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
            if ($payment->expense_id !== null) {
                DB::table('expenses')->where('id', $payment->expense_id)->update([
                    'status' => ExpenseStatus::Cancelled->value,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'decision_note' => $reason,
                ]);
            }
            $this->books->voided(self::paymentReference($payment), $reason);
            Audit::record('petty_cash.voided', $payment, ['fund' => $fund->name, 'reason' => $reason], actor: $by);
        });
    }

    /**
     * Asks to restore the box to its float, covering the payments so far.
     *
     * @throws ValidationException
     */
    public function requestTopUp(PettyCashFund $fund, User $by): PettyCashTopUp
    {
        return DB::transaction(function () use ($fund, $by) {
            $locked = $this->lock($fund);
            $this->requireActive($locked);
            if ($locked->topUps()->where('status', PettyCashTopUp::SUBMITTED)->exists()) {
                throw ValidationException::withMessages(['top_up' => 'A top-up is already waiting for a decision.']);
            }

            $amount = $locked->float_minor - $this->balance($locked);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['top_up' => 'The box already holds its full amount.']);
            }

            /** @var PettyCashTopUp $topUp */
            $topUp = $locked->topUps()->create([
                'amount_minor' => $amount,
                'status' => PettyCashTopUp::SUBMITTED,
                'requested_by' => $by->id,
            ]);
            $covered = $locked->payments()->whereNull('voided_at')->whereNull('top_up_id')->update(['top_up_id' => $topUp->id]);

            Audit::record('petty_cash.top_up_requested', $topUp, ['fund' => $locked->name, 'amount_minor' => $amount, 'payments' => $covered], actor: $by);
            Alerts::tell(
                Alerts::holders('finance.pettycash.approve')->reject(fn (User $user) => $user->id === $locked->custodian_id),
                UserNotification::APPROVAL,
                "{$by->name} asks you to check a petty cash top-up",
                "{$locked->name}, ".Money::format($amount, $locked->currency),
                route('petty-cash.show', $locked, false),
                $by,
            );

            return $topUp;
        });
    }

    /**
     * @throws ValidationException
     */
    public function approveTopUp(PettyCashTopUp $topUp, CarbonImmutable $on, ?string $note, User $by): void
    {
        DB::transaction(function () use ($topUp, $on, $note, $by) {
            $fund = $this->lockById($topUp->fund_id);
            $this->requireActive($fund);
            $this->requireDecidable($topUp, $fund, $by);

            $topUp->forceFill([
                'status' => PettyCashTopUp::APPROVED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $this->moved($fund, "petty-cash-top-up:{$topUp->id}", 'in', $topUp->amount_minor, $on, "Petty cash {$fund->name}: top-up", $by);
            Audit::record('petty_cash.top_up_approved', $topUp, ['fund' => $fund->name, 'amount_minor' => $topUp->amount_minor], actor: $by);
            $this->tellCustodian($fund, $by, "{$by->name} approved the top-up of {$fund->name}");
        });
    }

    /**
     * Its payments go back to waiting: the custodian can void any that are
     * wrong, and ask again.
     *
     * @throws ValidationException
     */
    public function rejectTopUp(PettyCashTopUp $topUp, string $reason, User $by): void
    {
        DB::transaction(function () use ($topUp, $reason, $by) {
            $fund = $this->lockById($topUp->fund_id);
            $this->requireActive($fund);
            $this->requireDecidable($topUp, $fund, $by);

            $fund->payments()->where('top_up_id', $topUp->id)->update(['top_up_id' => null]);
            $topUp->forceFill([
                'status' => PettyCashTopUp::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $reason,
            ])->save();
            Audit::record('petty_cash.top_up_rejected', $topUp, ['fund' => $fund->name, 'reason' => $reason], actor: $by);
            $this->tellCustodian($fund, $by, "{$by->name} rejected the top-up of {$fund->name}: {$reason}");
        });
    }

    /**
     * @throws ValidationException
     */
    public function count(PettyCashFund $fund, int $countedMinor, CarbonImmutable $on, ?string $note, User $by): PettyCashCount
    {
        return DB::transaction(function () use ($fund, $countedMinor, $on, $note, $by) {
            $locked = $this->lock($fund);
            $this->requireActive($locked);
            if ($locked->custodian_id === $by->id) {
                throw ValidationException::withMessages(['counted' => 'Someone other than the custodian counts the box.']);
            }

            $book = $this->balance($locked);
            $difference = $countedMinor - $book;
            if ($difference !== 0 && $note === null) {
                throw ValidationException::withMessages(['note' => 'Say what explains the difference, or that nothing does.']);
            }

            /** @var PettyCashCount $count */
            $count = $locked->counts()->create([
                'counted_on' => $on->toDateString(),
                'counted_minor' => $countedMinor,
                'book_minor' => $book,
                'difference_minor' => $difference,
                'note' => $note,
                'counted_by' => $by->id,
            ]);

            if ($difference !== 0) {
                $this->events->send('finance.cash.counted', "petty-cash-count:{$count->id}", [
                    'reference' => "petty-cash-count:{$count->id}",
                    'currency' => $locked->currency,
                    'counted_on' => $on->toDateString(),
                    'expected_minor' => $book,
                    'counted_minor' => $countedMinor,
                    'difference_minor' => $difference,
                    'counted_by' => $by->name,
                    'note' => mb_substr($note, 0, 255),
                ], $on);
            }

            Audit::record('petty_cash.counted', $count, ['fund' => $locked->name, 'counted_minor' => $countedMinor, 'book_minor' => $book, 'difference_minor' => $difference], actor: $by);

            return $count;
        });
    }

    /**
     * @throws ValidationException
     */
    public function changeCustodian(PettyCashFund $fund, User $custodian, User $by): void
    {
        DB::transaction(function () use ($fund, $custodian, $by) {
            $locked = $this->lock($fund);
            $this->requireActive($locked);
            if ($locked->custodian_id === $custodian->id) {
                return;
            }

            $from = $locked->custodian_id;
            $locked->forceFill(['custodian_id' => $custodian->id])->save();
            Audit::record('petty_cash.custodian_changed', $locked, ['fund' => $locked->name, 'from' => $from, 'to' => $custodian->id], actor: $by);
        });
    }

    /**
     * Returns what is in the box to the bank.
     *
     * @throws ValidationException
     */
    public function close(PettyCashFund $fund, CarbonImmutable $on, User $by): void
    {
        DB::transaction(function () use ($fund, $on, $by) {
            $locked = $this->lock($fund);
            $this->requireActive($locked);
            if ($locked->topUps()->where('status', PettyCashTopUp::SUBMITTED)->exists()) {
                throw ValidationException::withMessages(['close' => 'A top-up is waiting for a decision: decide it first.']);
            }

            $balance = $this->balance($locked);
            if ($balance < 0) {
                throw ValidationException::withMessages(['close' => 'The box is overdrawn: count it first.']);
            }

            if ($balance > 0) {
                $this->moved($locked, "petty-cash:{$locked->id}:close", 'out', $balance, $on, "Petty cash {$locked->name}: closed", $by);
            }
            $locked->forceFill(['status' => PettyCashFund::CLOSED, 'closed_at' => now()])->save();
            Audit::record('petty_cash.fund_closed', $locked, ['name' => $locked->name, 'returned_minor' => $balance], actor: $by);
        });
    }

    /**
     * What the box holds: the float, approved top-ups, less payments, with
     * what counts found. Nothing once it is closed.
     */
    public function balance(PettyCashFund $fund): int
    {
        if (! $fund->isActive()) {
            return 0;
        }

        return $fund->float_minor
            + (int) $fund->topUps()->where('status', PettyCashTopUp::APPROVED)->sum('amount_minor')
            - (int) $fund->payments()->whereNull('voided_at')->sum('amount_minor')
            + (int) $fund->counts()->sum('difference_minor');
    }

    /**
     * Payments no top-up has yet paid back.
     */
    public function outstanding(PettyCashFund $fund): int
    {
        return (int) $fund->payments()->whereNull('voided_at')
            ->where(fn ($query) => $query->whereNull('top_up_id')->orWhereIn('top_up_id', $fund->topUps()->where('status', PettyCashTopUp::SUBMITTED)->select('id')))
            ->sum('amount_minor');
    }

    public static function paymentReference(PettyCashPayment $payment): string
    {
        return "petty-cash-payment:{$payment->id}";
    }

    /**
     * Cash into the box from the bank, or back out to it.
     */
    private function moved(PettyCashFund $fund, string $reference, string $direction, int $amount, CarbonImmutable $on, string $description, User $by): void
    {
        $this->events->send('finance.cash.moved', $reference, [
            'reference' => $reference,
            'currency' => $fund->currency,
            'moved_on' => $on->toDateString(),
            'direction' => $direction,
            'counterpart' => 'bank',
            'amount_minor' => $amount,
            'description' => mb_substr($description, 0, 255),
            'moved_by' => $by->name,
        ], $on);
    }

    /**
     * @throws ValidationException
     */
    private function requireWithinLimit(int $amount): void
    {
        $limit = $this->organization()->petty_cash_max_payment_minor;
        if ($limit === null) {
            throw ValidationException::withMessages(['amount' => 'The organization has not set its petty cash limit: set it in Administration, Settings.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'More than zero.']);
        }
        if ($amount > $limit) {
            throw ValidationException::withMessages(['amount' => 'The most one payment can be is '.Money::format($limit, Money::currency()).'.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireActive(PettyCashFund $fund): void
    {
        if ($fund->isMoved()) {
            throw ValidationException::withMessages(['fund' => "{$fund->name} is in Finance now: use it there."]);
        }
        if (! $fund->isActive()) {
            throw ValidationException::withMessages(['fund' => "{$fund->name} is closed."]);
        }
        $this->requireOwnPettyCash();
    }

    /**
     * @throws ValidationException
     */
    private function requireOwnPettyCash(): void
    {
        if ($this->keptInFinance()) {
            throw ValidationException::withMessages(['fund' => 'Petty cash is kept in Finance: move the box there, and use it there.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireCustodian(PettyCashFund $fund, User $by): void
    {
        if ($fund->custodian_id !== $by->id) {
            throw ValidationException::withMessages(['amount' => "Only the custodian pays from {$fund->name}."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireDecidable(PettyCashTopUp $topUp, PettyCashFund $fund, User $by): void
    {
        $topUp->refresh();
        if (! $topUp->isSubmitted()) {
            throw ValidationException::withMessages(['top_up' => 'This top-up has already been decided.']);
        }
        if ($fund->custodian_id === $by->id) {
            throw ValidationException::withMessages(['top_up' => 'Someone other than the custodian decides a top-up.']);
        }
        if ($topUp->requested_by === $by->id) {
            throw ValidationException::withMessages(['top_up' => 'Someone other than whoever asked decides a top-up.']);
        }
    }

    private function tellCustodian(PettyCashFund $fund, User $by, string $title): void
    {
        Alerts::tell([$fund->custodian], UserNotification::DECISION, $title, null, route('petty-cash.show', $fund, false), $by);
    }

    private function organization(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    private function lock(PettyCashFund $fund): PettyCashFund
    {
        return $this->lockById($fund->id);
    }

    private function lockById(string $id): PettyCashFund
    {
        return PettyCashFund::query()->lockForUpdate()->whereKey($id)->firstOrFail();
    }
}
