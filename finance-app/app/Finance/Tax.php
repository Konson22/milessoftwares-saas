<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tax returns. Every tax amount sits on the one tax account (2200): output
 * tax on sales credited, input tax on purchases debited, each line saying
 * which it is and on which code. A return for a period reports that
 * account's movement:
 *
 *   output       credits less debits on output lines (voids and returns lower it)
 *   input        debits less credits on input lines
 *   adjustments  lines with no kind: manual journals, tax entered without a code
 *   net          output - input + adjustments, owed to the tax authority
 *
 * Settlements (paying the authority, or its refund) and opening balances are
 * not part of any period. Returns follow one another with no gap; filing
 * one locks its period for journals that touch the tax account (see
 * Periods::postingDate), and only the latest can be unfiled.
 */
class Tax
{
    public const SETTLEMENT = 'tax_settlement';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Periods $periods,
    ) {}

    /**
     * Tax per code on lines with a code (zero-rated codes left out: a
     * journal line is never zero), each line rounded on its own.
     *
     * @param  list<array{amount_minor: int, tax_code?: TaxCode|null}>  $lines
     * @return array<string, array{code: TaxCode, base: int, tax: int}>
     */
    public static function byCode(array $lines): array
    {
        $byCode = [];
        foreach ($lines as $line) {
            $code = $line['tax_code'] ?? null;
            if ($code === null || $code->rate_bp === 0) {
                continue;
            }
            $byCode[$code->id] ??= ['code' => $code, 'base' => 0, 'tax' => 0];
            $byCode[$code->id]['base'] += $line['amount_minor'];
            $byCode[$code->id]['tax'] += $code->taxOn($line['amount_minor']);
        }

        return $byCode;
    }

    /**
     * Ledger lines for tax per code: credits for output tax, debits for input.
     *
     * @param  array<string, array{code: TaxCode, base: int, tax: int}>  $byCode
     * @return list<array{account: Account, debit?: int, credit?: int, tax: array{kind: string, code: TaxCode, base: int}}>
     */
    public static function journalLines(array $byCode, string $kind): array
    {
        $lines = [];
        foreach ($byCode as $tax) {
            if ($tax['tax'] === 0) {
                continue;
            }
            $lines[] = [
                'account' => Account::system(Account::TAX),
                $kind === JournalLine::OUTPUT ? 'credit' : 'debit' => $tax['tax'],
                'tax' => ['kind' => $kind, 'code' => $tax['code'], 'base' => $tax['base']],
            ];
        }

        return $lines;
    }

    /**
     * @return array{
     *     from: string, to: string,
     *     rows: list<array{kind: string|null, code: string|null, base_minor: int|null, tax_minor: int}>,
     *     output_minor: int, input_minor: int, adjustments_minor: int, net_minor: int,
     * }
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tax = Account::system(Account::TAX);
        $groups = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->leftJoin('tax_codes', 'tax_codes.id', '=', 'journal_lines.tax_code_id')
            ->where('journal_lines.account_id', $tax->id)
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->whereNotIn('journals.source_type', [self::SETTLEMENT, Journal::OPENING_BALANCES])
            ->groupBy('journal_lines.tax_kind', 'journal_lines.tax_code_id', 'tax_codes.code', 'tax_codes.rate_bp')
            ->orderBy('journal_lines.tax_kind')
            ->orderBy('tax_codes.code')
            ->selectRaw('journal_lines.tax_kind as kind, tax_codes.code as code, tax_codes.rate_bp as rate_bp, sum(journal_lines.debit_minor) as debits, sum(journal_lines.credit_minor) as credits, sum(journal_lines.tax_base_minor) as base')
            ->toBase()
            ->get();

        $rows = [];
        $totals = [JournalLine::OUTPUT => 0, JournalLine::INPUT => 0, '' => 0];
        foreach ($groups as $group) {
            $kind = $group->kind === null ? null : (string) $group->kind;
            $amount = $kind === JournalLine::INPUT
                ? (int) $group->debits - (int) $group->credits
                : (int) $group->credits - (int) $group->debits;
            $totals[$kind ?? ''] += $amount;
            $rows[] = [
                'kind' => $kind,
                'code' => $group->code === null ? null : (new TaxCode(['code' => $group->code, 'rate_bp' => (int) $group->rate_bp]))->label(),
                'base_minor' => $group->base === null ? null : (int) $group->base,
                'tax_minor' => $amount,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'output_minor' => $totals[JournalLine::OUTPUT],
            'input_minor' => $totals[JournalLine::INPUT],
            'adjustments_minor' => $totals[''],
            'net_minor' => $totals[JournalLine::OUTPUT] - $totals[JournalLine::INPUT] + $totals[''],
        ];
    }

    /**
     * The first day the next return covers: the day after the latest, or
     * the day the books start (null when neither is known; the first return
     * then says).
     */
    public function nextStart(): ?CarbonImmutable
    {
        return $this->latest()?->ends_on->addDay() ?? $this->periods->booksStart();
    }

    public function latest(): ?TaxReturn
    {
        return TaxReturn::query()->orderByDesc('ends_on')->first();
    }

    /**
     * Files the return from the next start (or $startsOn, for the first) to
     * $endsOn with the totals as they stand.
     *
     * @throws ValidationException
     */
    public function file(?CarbonImmutable $startsOn, CarbonImmutable $endsOn, ?string $reference, User $by): TaxReturn
    {
        return DB::transaction(function () use ($startsOn, $endsOn, $reference, $by) {
            $latest = TaxReturn::query()->orderByDesc('ends_on')->lockForUpdate()->first();
            $startsOn = $latest?->ends_on->addDay() ?? $startsOn ?? $this->periods->booksStart();

            if ($startsOn === null) {
                throw ValidationException::withMessages(['starts_on' => 'Say when the first return starts.']);
            }
            if ($endsOn->lessThan($startsOn)) {
                throw ValidationException::withMessages(['ends_on' => "This return starts on {$startsOn->format('j M Y')}; end it then or later."]);
            }
            if ($endsOn->greaterThanOrEqualTo(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['ends_on' => 'A return covers days that are over: end it yesterday or earlier.']);
            }

            $report = $this->report($startsOn, $endsOn);

            return TaxReturn::query()->create([
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reference' => $reference,
                'output_minor' => $report['output_minor'],
                'input_minor' => $report['input_minor'],
                'adjustments_minor' => $report['adjustments_minor'],
                'net_minor' => $report['net_minor'],
                'filed_at' => now(),
                'filed_by' => $by->id,
            ]);
        });
    }

    /**
     * Pays what the return owes from $bank (Dr tax / Cr bank), or takes in
     * its refund (Dr bank / Cr tax).
     *
     * @throws ValidationException
     */
    public function settle(TaxReturn $return, Account $bank, CarbonImmutable $date, User $by): TaxReturn
    {
        if (! $bank->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Settle from a bank or cash account.']);
        }

        return DB::transaction(function () use ($return, $bank, $date, $by) {
            $locked = TaxReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->settlement_journal_id !== null) {
                throw ValidationException::withMessages(['return' => 'This return is already settled.']);
            }
            if ($locked->net_minor === 0) {
                throw ValidationException::withMessages(['return' => 'Nothing is owed either way on this return.']);
            }

            $amount = abs($locked->net_minor);
            $owed = $locked->net_minor > 0;
            $period = $locked->starts_on->format('j M Y').' to '.$locked->ends_on->format('j M Y');
            $journal = $this->ledger->post($date, ($owed ? 'Tax paid for ' : 'Tax refund for ').$period, [
                ['account' => Account::system(Account::TAX), $owed ? 'debit' : 'credit' => $amount],
                ['account' => $bank, $owed ? 'credit' : 'debit' => $amount],
            ], ['source_type' => self::SETTLEMENT, 'source_reference' => $locked->reference], $by);

            $locked->forceFill(['settlement_journal_id' => $journal->id])->save();

            return $locked;
        });
    }

    /**
     * Unfiles the latest return; its settlement, if any, is reversed today.
     *
     * @throws ValidationException
     */
    public function unfile(TaxReturn $return, User $by): void
    {
        DB::transaction(function () use ($return, $by) {
            $latest = TaxReturn::query()->orderByDesc('ends_on')->lockForUpdate()->first();

            if ($latest === null || $latest->id !== $return->id) {
                throw ValidationException::withMessages(['return' => 'Only the latest return can be unfiled.']);
            }

            $settlement = $latest->settlement_journal_id;
            $latest->delete();

            if ($settlement !== null) {
                $this->ledger->reverse(
                    Journal::query()->whereKey($settlement)->firstOrFail(),
                    CarbonImmutable::today(),
                    'Tax return '.$latest->starts_on->format('j M Y').' to '.$latest->ends_on->format('j M Y').' unfiled',
                    $by,
                    self::SETTLEMENT,
                );
            }
        });
    }
}
