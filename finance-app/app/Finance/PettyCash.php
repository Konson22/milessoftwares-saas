<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\PaymentRequest;
use App\Models\PettyCashCount;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Petty cash as an imprest fund, in the one ledger:
 *
 * - A fund is a petty cash account of the chart with a fixed float and a
 *   custodian. What it holds is that account's balance; nothing else keeps
 *   a balance.
 * - Money reaches it only through a transfer payment request (approved and
 *   disbursed like any other), for the float less what it holds: the first
 *   one sets it up, later ones replenish it. One may be open at a time, and
 *   only once every voucher has been checked, so nothing is paid back twice.
 * - Only the custodian pays from it, with a receipt, up to the
 *   organization's petty cash limit and never more than it holds. The
 *   voucher posts at once (Dr what it was spent on, Cr the fund), so the
 *   expense is in the books once, in its own month.
 * - Someone other than the custodian and the recorder checks each voucher; a
 *   rejected one is reversed, and the custodian owes the box the money.
 * - Someone other than the custodian counts the cash. A difference is posted
 *   to Cash over and short, never without an explanation.
 * - A fund closes only empty, checked and with nothing on its way to it.
 *
 * Owners are held to the same rules. Every step is audited.
 */
class PettyCash
{
    public const RECEIPT_DISK = 'local';

    public function __construct(
        private readonly Banking $banking,
        private readonly Ledger $ledger,
        private readonly PaymentRequests $requests,
        private readonly PaymentPolicy $policy,
        private readonly DocumentNumbers $numbers,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createFund(string $name, Account $account, User $custodian, int $floatMinor, ?int $replenishAtMinor, User $by): PettyCashFund
    {
        if ($account->bank_kind !== BankKind::PettyCash || ! $account->active) {
            throw ValidationException::withMessages(['account_id' => 'Choose an open petty cash account.']);
        }
        $this->checkAmounts($floatMinor, $replenishAtMinor);

        return DB::transaction(function () use ($name, $account, $custodian, $floatMinor, $replenishAtMinor, $by) {
            if (PettyCashFund::query()->where('account_id', $account->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['account_id' => "{$account->name} already has a fund."]);
            }
            if (PettyCashFund::query()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => 'Another fund has this name.']);
            }

            $fund = PettyCashFund::query()->create([
                'name' => $name,
                'account_id' => $account->id,
                'custodian_id' => $custodian->id,
                'float_minor' => $floatMinor,
                'replenish_at_minor' => $replenishAtMinor,
                'status' => PettyCashFund::ACTIVE,
                'created_by' => $by->id,
            ]);
            $this->audit->record('petty_cash.fund_created', $fund, null, $this->terms($fund), $by);

            return $fund;
        });
    }

    /**
     * The float, the top-up level and whether it is in use. A fund is closed
     * with close(), not here.
     *
     * @throws ValidationException
     */
    public function update(PettyCashFund $fund, int $floatMinor, ?int $replenishAtMinor, string $status, User $by): PettyCashFund
    {
        if (! in_array($status, [PettyCashFund::ACTIVE, PettyCashFund::SUSPENDED], true)) {
            throw ValidationException::withMessages(['status' => 'Active or suspended.']);
        }
        $this->checkAmounts($floatMinor, $replenishAtMinor);

        return DB::transaction(function () use ($fund, $floatMinor, $replenishAtMinor, $status, $by) {
            $locked = $this->open($fund);
            $before = $this->terms($locked);
            $locked->forceFill(['float_minor' => $floatMinor, 'replenish_at_minor' => $replenishAtMinor, 'status' => $status])->save();
            if ($locked->wasChanged()) {
                $this->audit->record('petty_cash.fund_changed', $locked, $before, $this->terms($locked), $by);
            }

            return $locked;
        });
    }

    /**
     * A handover: the cash is counted first, after the last voucher, so the
     * new custodian takes over a known amount.
     *
     * @throws ValidationException
     */
    public function changeCustodian(PettyCashFund $fund, User $custodian, User $by): PettyCashFund
    {
        return DB::transaction(function () use ($fund, $custodian, $by) {
            $locked = $this->open($fund);
            if ($locked->custodian_id !== null && $locked->custodian_id === $custodian->id) {
                throw ValidationException::withMessages(['custodian' => 'They already hold this fund.']);
            }
            if (! $this->countedSinceLastVoucher($locked)) {
                throw ValidationException::withMessages(['custodian' => 'Count the cash before handing the fund over.']);
            }

            $before = $this->terms($locked);
            $locked->forceFill(['custodian_id' => $custodian->id])->save();
            $this->audit->record('petty_cash.custodian_changed', $locked, $before, $this->terms($locked), $by);

            return $locked;
        });
    }

    /**
     * @param  array{paid_on: CarbonImmutable, amount_minor: int, payee: string|null, description: string, account: Account, cost_centre: DimensionValue|null, project: DimensionValue|null}  $details
     *
     * @throws ValidationException
     */
    public function pay(PettyCashFund $fund, array $details, UploadedFile $receipt, User $by): PettyCashVoucher
    {
        $account = $details['account'];
        if (! $account->active || $account->isBank() || ($account->type !== AccountType::Expense && $account->system_key !== Account::EMPLOYEE_ADVANCES)) {
            throw ValidationException::withMessages(['account_id' => 'Choose an expense account, or Employee advances for money handed to staff.']);
        }
        if ($account->requires_cost_centre && $details['cost_centre'] === null) {
            throw ValidationException::withMessages(['cost_centre_id' => "{$account->name} needs a cost centre."]);
        }
        if ($account->requires_project && $details['project'] === null) {
            throw ValidationException::withMessages(['project_id' => "{$account->name} needs a project."]);
        }
        if ($details['paid_on']->isFuture()) {
            throw ValidationException::withMessages(['paid_on' => 'It cannot be paid in the future.']);
        }
        $amount = $details['amount_minor'];
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }

        $path = 'petty-cash/'.$fund->organization_id.'/'.Str::uuid7().'.'.($receipt->guessExtension() ?? 'bin');
        if (Storage::disk(self::RECEIPT_DISK)->putFileAs(dirname($path), $receipt, basename($path)) === false) {
            throw ValidationException::withMessages(['receipt' => 'The receipt could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($fund, $details, $account, $amount, $receipt, $path, $by) {
                $locked = $this->open($fund);
                if ($locked->status !== PettyCashFund::ACTIVE) {
                    throw ValidationException::withMessages(['fund' => "{$locked->name} is suspended."]);
                }
                if ($locked->custodian_id !== $by->id) {
                    throw ValidationException::withMessages(['fund' => 'Only the custodian pays from this fund.']);
                }

                $books = $this->policy->booksAmount($amount, $locked->account->currency, $details['paid_on']);
                $limit = Workspace::current()?->petty_cash_max_minor;
                if ($limit === null) {
                    throw ValidationException::withMessages(['amount' => 'The organization has not set its petty cash limit (Approval rules).']);
                }
                if ($books > $limit) {
                    throw ValidationException::withMessages(['amount' => 'The most one voucher may be is '.Money::toMajor($limit).' '.Money::currency().'.']);
                }

                $box = $this->lockAccount($locked);
                $held = $box->heldMinor();
                if ($amount > $held) {
                    throw ValidationException::withMessages(['amount' => "{$locked->name} holds only ".Money::toMajor($held, $box->currency).'.']);
                }

                $number = $this->numbers->next('petty_cash_voucher', 'PCV', 5);
                $journal = $this->banking->moneyOut($box, $account, $amount, $details['paid_on'], mb_substr("{$details['description']} ({$number})", 0, 255), $by, null, [
                    'cost_centre' => $details['cost_centre'],
                    'project' => $details['project'],
                ]);

                $voucher = PettyCashVoucher::query()->create([
                    'fund_id' => $locked->id,
                    'number' => $number,
                    'paid_on' => $details['paid_on'],
                    'amount_minor' => $amount,
                    'payee' => $details['payee'],
                    'description' => $details['description'],
                    'account_id' => $account->id,
                    'cost_centre_id' => $details['cost_centre']?->id,
                    'project_id' => $details['project']?->id,
                    'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 255),
                    'receipt_path' => $path,
                    'receipt_mime' => mb_substr($receipt->getMimeType() ?? 'application/octet-stream', 0, 100),
                    'receipt_size' => (int) $receipt->getSize(),
                    'status' => PettyCashVoucher::RECORDED,
                    'journal_id' => $journal->id,
                    'recorded_by' => $by->id,
                ]);
                $this->audit->record('petty_cash.voucher_recorded', $voucher, null, [
                    'fund' => $locked->name,
                    'amount_minor' => $amount,
                    'currency' => $box->currency,
                    'paid_on' => $voucher->paid_on->toDateString(),
                    'account' => $account->code,
                    'payee' => $voucher->payee,
                    'journal' => $journal->number,
                ], $by);

                return $voucher;
            });
        } catch (Throwable $e) {
            Storage::disk(self::RECEIPT_DISK)->delete($path);
            throw $e;
        }
    }

    /**
     * @throws ValidationException
     */
    public function verify(PettyCashVoucher $voucher, User $by): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $by) {
            $locked = $this->checkable($voucher, $by);
            $locked->forceFill(['status' => PettyCashVoucher::VERIFIED, 'checked_by' => $by->id, 'checked_at' => now()])->save();
            $this->audit->record('petty_cash.voucher_verified', $locked, ['status' => PettyCashVoucher::RECORDED], ['status' => $locked->status], $by);

            return $locked;
        });
    }

    /**
     * Reverses its journal, dated today: the box's books go back up, and a
     * count will show the cash missing until the custodian repays it.
     *
     * @throws ValidationException
     */
    public function reject(PettyCashVoucher $voucher, string $note, User $by): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $note, $by) {
            $locked = $this->checkable($voucher, $by);
            $reversal = $this->ledger->reverse($locked->journal()->firstOrFail(), CarbonImmutable::today(), "Petty cash voucher {$locked->number} rejected", $by);
            $locked->forceFill([
                'status' => PettyCashVoucher::REJECTED,
                'checked_by' => $by->id,
                'checked_at' => now(),
                'reject_note' => $note,
                'reversal_journal_id' => $reversal->id,
            ])->save();
            $this->audit->record('petty_cash.voucher_rejected', $locked, ['status' => PettyCashVoucher::RECORDED], ['status' => $locked->status, 'note' => $note, 'reversal' => $reversal->number], $by);

            return $locked;
        });
    }

    /**
     * Asks for the float less what the fund holds, from a bank in the fund's
     * currency, as a transfer payment request. The first one sets the fund up.
     *
     * @throws ValidationException
     */
    public function replenish(PettyCashFund $fund, Account $bank, CarbonImmutable $payOn, User $by): PaymentRequest
    {
        return DB::transaction(function () use ($fund, $bank, $payOn, $by) {
            $locked = $this->open($fund);
            $box = $this->lockAccount($locked);
            if (! $bank->isBank() || ! $bank->active || $bank->id === $box->id || $bank->bank_kind === BankKind::PettyCash) {
                throw ValidationException::withMessages(['account_id' => 'Choose the bank or cash account it comes from.']);
            }
            if ($bank->currency !== $box->currency) {
                throw ValidationException::withMessages(['account_id' => 'Choose an account in the fund\'s currency ('.($box->currency ?? Money::currency()).').']);
            }
            if ($locked->vouchers()->where('status', PettyCashVoucher::RECORDED)->exists()) {
                throw ValidationException::withMessages(['fund' => 'Some vouchers have not been checked yet.']);
            }

            $amount = $locked->float_minor - $box->heldMinor();
            if ($amount <= 0) {
                throw ValidationException::withMessages(['fund' => "{$locked->name} already holds its float."]);
            }

            $vouchers = array_values($locked->vouchers()->where('status', PettyCashVoucher::VERIFIED)->whereNull('replenished_by_id')->orderBy('number')->get()->map(fn (PettyCashVoucher $voucher): string => $voucher->id)->all());
            $request = $this->requests->replenish($locked, $bank, $box, $amount, $payOn, $vouchers, $by);
            $this->audit->record('petty_cash.replenishment_requested', $locked, null, [
                'request' => $request->number,
                'amount_minor' => $amount,
                'currency' => $box->currency,
                'vouchers' => count($vouchers),
            ], $by);

            return $request;
        });
    }

    /**
     * @throws ValidationException
     */
    public function count(PettyCashFund $fund, int $countedMinor, ?string $note, User $by): PettyCashCount
    {
        if ($countedMinor < 0) {
            throw ValidationException::withMessages(['counted' => 'Zero or more.']);
        }

        return DB::transaction(function () use ($fund, $countedMinor, $note, $by) {
            $locked = $this->open($fund);
            if ($locked->custodian_id === $by->id) {
                throw ValidationException::withMessages(['counted' => 'Someone other than the custodian counts the cash.']);
            }

            $box = $this->lockAccount($locked);
            $book = $box->heldMinor();
            $difference = $countedMinor - $book;
            if ($difference !== 0 && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Explain the difference, or say that nothing does.']);
            }

            $today = CarbonImmutable::today();
            $journal = null;
            if ($difference !== 0) {
                $overShort = Account::system(Account::CASH_OVER_SHORT);
                $memo = mb_substr(($difference > 0 ? 'Cash over' : 'Cash short')." in {$locked->name}: {$note}", 0, 255);
                $journal = $difference > 0
                    ? $this->banking->moneyIn($box, $overShort, $difference, $today, $memo, $by)
                    : $this->banking->moneyOut($box, $overShort, -$difference, $today, $memo, $by);
            }

            $count = PettyCashCount::query()->create([
                'fund_id' => $locked->id,
                'counted_on' => $today,
                'counted_minor' => $countedMinor,
                'book_minor' => $book,
                'difference_minor' => $difference,
                'note' => $note,
                'journal_id' => $journal?->id,
                'counted_by' => $by->id,
            ]);
            $this->audit->record('petty_cash.counted', $locked, ['book_minor' => $book], [
                'counted_minor' => $countedMinor,
                'difference_minor' => $difference,
                'currency' => $box->currency,
                'note' => $note,
                'journal' => $journal?->number,
            ], $by);

            return $count;
        });
    }

    /**
     * Only once its cash has gone back (a transfer request), every voucher
     * is checked and nothing is on its way to it.
     *
     * @throws ValidationException
     */
    public function close(PettyCashFund $fund, User $by): PettyCashFund
    {
        return DB::transaction(function () use ($fund, $by) {
            $locked = $this->open($fund);
            $box = $this->lockAccount($locked);
            if ($locked->vouchers()->where('status', PettyCashVoucher::RECORDED)->exists()) {
                throw ValidationException::withMessages(['fund' => 'Some vouchers have not been checked yet.']);
            }
            if (PaymentRequest::query()->whereIn('status', PaymentRequest::OPEN)->where(fn ($query) => $query->where('account_id', $box->id)->orWhere('other_account_id', $box->id))->exists()) {
                throw ValidationException::withMessages(['fund' => 'A payment request to or from this fund is still open.']);
            }
            if (($held = $box->heldMinor()) !== 0) {
                throw ValidationException::withMessages(['fund' => "{$locked->name} still holds ".Money::toMajor($held, $box->currency).': return it to a bank with a transfer request, or count it, first.']);
            }

            $before = $this->terms($locked);
            $locked->forceFill(['status' => PettyCashFund::CLOSED, 'closed_by' => $by->id, 'closed_at' => now()])->save();
            $this->audit->record('petty_cash.fund_closed', $locked, $before, $this->terms($locked), $by);

            return $locked;
        });
    }

    /**
     * The organization's petty cash limit per voucher, in the books' currency.
     */
    public function setLimit(?int $maxMinor, User $by): void
    {
        if ($maxMinor !== null && $maxMinor <= 0) {
            throw ValidationException::withMessages(['petty_cash_max' => 'More than zero, or empty.']);
        }

        DB::transaction(function () use ($maxMinor, $by) {
            $workspace = Workspace::query()->lockForUpdate()->firstOrFail();
            $before = $workspace->petty_cash_max_minor;
            if ($before === $maxMinor) {
                return;
            }
            $workspace->forceFill(['petty_cash_max_minor' => $maxMinor])->save();
            $this->audit->record('approval_rules.petty_cash_changed', null, ['max_minor' => $before], ['max_minor' => $maxMinor], $by);
        });
    }

    /**
     * Whether the fund is due for a top-up: at or below its level.
     */
    public static function due(PettyCashFund $fund, int $held): bool
    {
        return $fund->status !== PettyCashFund::CLOSED && $fund->replenish_at_minor !== null && $held <= $fund->replenish_at_minor;
    }

    private function checkAmounts(int $floatMinor, ?int $replenishAtMinor): void
    {
        if ($floatMinor <= 0) {
            throw ValidationException::withMessages(['float' => 'The float must be more than zero.']);
        }
        if ($replenishAtMinor !== null && ($replenishAtMinor < 0 || $replenishAtMinor >= $floatMinor)) {
            throw ValidationException::withMessages(['replenish_at' => 'Below the float, or empty.']);
        }
    }

    private function countedSinceLastVoucher(PettyCashFund $fund): bool
    {
        $counted = $fund->counts()->latest('created_at')->value('created_at');
        if ($counted === null) {
            return false;
        }
        $paid = $fund->vouchers()->latest('created_at')->value('created_at');

        return $paid === null || CarbonImmutable::parse($counted)->greaterThanOrEqualTo(CarbonImmutable::parse($paid));
    }

    /**
     * @throws ValidationException
     */
    private function checkable(PettyCashVoucher $voucher, User $by): PettyCashVoucher
    {
        $locked = PettyCashVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PettyCashVoucher::RECORDED) {
            throw ValidationException::withMessages(['voucher' => "{$locked->number} has already been checked."]);
        }
        $fund = PettyCashFund::query()->findOrFail($locked->fund_id);
        if ($fund->custodian_id === $by->id || $locked->recorded_by === $by->id) {
            throw ValidationException::withMessages(['voucher' => 'Someone other than the custodian checks their vouchers.']);
        }

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    private function open(PettyCashFund $fund): PettyCashFund
    {
        $locked = PettyCashFund::query()->whereKey($fund->id)->lockForUpdate()->firstOrFail();
        if ($locked->status === PettyCashFund::CLOSED) {
            throw ValidationException::withMessages(['fund' => "{$locked->name} is closed."]);
        }

        return $locked;
    }

    /**
     * The fund's account, locked: vouchers, counts and payments out of it
     * (PaymentRequests) take their turn.
     */
    private function lockAccount(PettyCashFund $fund): Account
    {
        return Account::query()->whereKey($fund->account_id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(PettyCashFund $fund): array
    {
        return [
            'name' => $fund->name,
            'account_id' => $fund->account_id,
            'custodian_id' => $fund->custodian_id,
            'float_minor' => $fund->float_minor,
            'replenish_at_minor' => $fund->replenish_at_minor,
            'status' => $fund->status,
        ];
    }
}
