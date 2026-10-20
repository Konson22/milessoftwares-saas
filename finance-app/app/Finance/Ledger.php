<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double entry. Every journal has two or more lines, each a debit or a
 * credit to an active account, and debits equal credits. Journals are never
 * edited: a mistake is reversed by another journal. PostgreSQL checks the
 * balance again when the transaction commits. The day must be open
 * (App\Finance\Periods): $source['periods'] says how a locked day is treated.
 */
class Ledger
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Periods $periods,
    ) {}

    /**
     * A line on the tax account may say which tax it is ("tax"): output or
     * input, its code, and the net amount it was charged on. A journal that
     * touches the tax account also respects filed tax returns.
     *
     * A line in another currency ("currency") keeps that amount too; lines on
     * an account held in another currency must have it. Debits and credits
     * are always in the books' currency, so the journal balances in it.
     *
     * A line may name a cost centre and a project. Entries made by hand
     * ($source['require_dimensions']) must name them on accounts that
     * require them; products' events and automatic entries need not.
     *
     * @param  list<array{account: Account, debit?: int, credit?: int, description?: string|null, tax?: array{kind: string, code?: TaxCode|null, base?: int|null}|null, cost_centre?: DimensionValue|null, project?: DimensionValue|null, currency?: array{code: string, amount: int}|null}>  $lines
     * @param  array{source_app?: string, source_type?: string, source_reference?: string|null, branch_id?: string|null, periods?: string, require_dimensions?: bool}  $source
     *
     * @throws ValidationException when the lines do not make a valid journal, or the day is locked
     */
    public function post(CarbonImmutable $date, string $memo, array $lines, array $source = [], ?User $by = null): Journal
    {
        // Closing a year also clears accounts closed during it.
        $this->check($lines, ($source['periods'] ?? null) === Periods::CLOSING, $source['require_dimensions'] ?? false);
        $tax = array_any($lines, fn (array $line) => $line['account']->system_key === Account::TAX);

        return DB::transaction(function () use ($date, $memo, $lines, $source, $by, $tax) {
            $postOn = $this->periods->postingDate($date, $source['periods'] ?? Periods::ENFORCE, $tax);
            if (! $postOn->isSameDay($date)) {
                $memo = mb_strimwidth($memo, 0, 220, '…')." (dated {$date->format('j M Y')}, books locked)";
                $date = $postOn;
            }

            $journal = new Journal([
                'number' => $this->numbers->next('journal', 'JV', 6),
                'entry_date' => $date,
                'memo' => $memo,
                'source_app' => $source['source_app'] ?? 'finance',
                'source_type' => $source['source_type'] ?? Journal::MANUAL,
                'source_reference' => $source['source_reference'] ?? null,
                'branch_id' => $source['branch_id'] ?? null,
                'created_by' => $by?->id,
            ]);
            $journal->save();

            foreach ($lines as $position => $line) {
                $journal->lines()->create([
                    'account_id' => $line['account']->id,
                    'position' => $position + 1,
                    'debit_minor' => $line['debit'] ?? 0,
                    'credit_minor' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                    'tax_kind' => $line['tax']['kind'] ?? null,
                    'tax_code_id' => ($line['tax']['code'] ?? null)?->id,
                    'tax_base_minor' => $line['tax']['base'] ?? null,
                    'cost_centre_id' => ($line['cost_centre'] ?? null)?->id,
                    'project_id' => ($line['project'] ?? null)?->id,
                    'currency' => $line['currency']['code'] ?? null,
                    'currency_minor' => isset($line['currency']) ? (($line['debit'] ?? 0) > 0 ? $line['currency']['amount'] : -$line['currency']['amount']) : null,
                ]);
            }

            return $journal;
        });
    }

    /**
     * Posts the mirror image of a journal and links the two.
     *
     * @throws ValidationException when it is already reversed, is itself a reversal, or the day is locked
     */
    public function reverse(Journal $journal, CarbonImmutable $date, string $memo, ?User $by = null, string $sourceType = Journal::REVERSAL, string $periods = Periods::ENFORCE): Journal
    {
        return DB::transaction(function () use ($journal, $date, $memo, $by, $sourceType, $periods) {
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if ($locked->reversed_by_id !== null) {
                throw ValidationException::withMessages(['journal' => "{$locked->number} is already reversed."]);
            }
            if ($locked->reversal_of_id !== null) {
                throw ValidationException::withMessages(['journal' => "{$locked->number} is a reversal; post a new journal instead."]);
            }
            // The bank says the money moved: unmatch it on its statement first.
            if (BankStatementLine::query()->whereIn('journal_line_id', JournalLine::query()->where('journal_id', $locked->id)->select('id'))->exists()) {
                throw ValidationException::withMessages(['journal' => "{$locked->number} is matched to a bank statement line; unmatch it there first."]);
            }

            $lines = [];
            foreach ($locked->lines()->with(['account', 'taxCode', 'costCentre', 'project'])->get() as $line) {
                $lines[] = [
                    'account' => $line->account,
                    'debit' => $line->credit_minor,
                    'credit' => $line->debit_minor,
                    'description' => $line->description,
                    // Still that kind of tax, taken back: a void lowers output tax.
                    'tax' => $line->tax_kind === null ? null : [
                        'kind' => $line->tax_kind,
                        'code' => $line->taxCode,
                        'base' => $line->tax_base_minor === null ? null : -$line->tax_base_minor,
                    ],
                    'cost_centre' => $line->costCentre,
                    'project' => $line->project,
                    // The other side, so the amount in the currency flips too.
                    'currency' => $line->currency === null ? null : ['code' => $line->currency, 'amount' => abs((int) $line->currency_minor)],
                ];
            }

            $reversal = $this->post($date, $memo, $lines, [
                'source_app' => $locked->source_app,
                'source_type' => $sourceType,
                'source_reference' => $locked->number,
                'branch_id' => $locked->branch_id,
                'periods' => $periods,
            ], $by);
            $reversal->forceFill(['reversal_of_id' => $locked->id])->save();
            $locked->forceFill(['reversed_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /**
     * @param  list<array{account: Account, debit?: int, credit?: int, description?: string|null, tax?: array<string, mixed>|null, cost_centre?: DimensionValue|null, project?: DimensionValue|null, currency?: array{code: string, amount: int}|null}>  $lines
     */
    private function check(array $lines, bool $closing = false, bool $requireDimensions = false): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least two lines.']);
        }

        $debits = 0;
        $credits = 0;

        foreach ($lines as $index => $line) {
            $debit = $line['debit'] ?? 0;
            $credit = $line['credit'] ?? 0;

            if ($debit < 0 || $credit < 0 || ($debit === 0) === ($credit === 0)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each line is either a debit or a credit, and not zero.']);
            }
            // An account held in another currency moves in that currency.
            $currency = $line['currency'] ?? null;
            if ($line['account']->currency !== null && ($currency === null || $currency['code'] !== $line['account']->currency)) {
                throw ValidationException::withMessages(["lines.{$index}" => "{$line['account']->label()} is held in {$line['account']->currency}: post to it from Banking, or with an amount in {$line['account']->currency}."]);
            }
            if ($currency !== null && $currency['amount'] < 0) {
                throw ValidationException::withMessages(["lines.{$index}" => 'An amount in another currency is not negative.']);
            }
            if (! $closing && ! $line['account']->active) {
                throw ValidationException::withMessages(["lines.{$index}" => "{$line['account']->label()} is closed."]);
            }
            foreach (['cost_centre' => DimensionValue::COST_CENTRE, 'project' => DimensionValue::PROJECT] as $key => $kind) {
                $value = $line[$key] ?? null;
                if ($value !== null && $value->kind !== $kind) {
                    throw ValidationException::withMessages(["lines.{$index}" => "{$value->label()} is not a ".strtolower(DimensionValue::kindLabel($kind)).'.']);
                }
                $required = $kind === DimensionValue::PROJECT ? $line['account']->requires_project : $line['account']->requires_cost_centre;
                if ($requireDimensions && $required && $value === null) {
                    throw ValidationException::withMessages(["lines.{$index}" => "{$line['account']->label()} needs a ".strtolower(DimensionValue::kindLabel($kind)).'.']);
                }
            }

            $debits += $debit;
            $credits += $credit;
        }

        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => 'Debits and credits must be equal.']);
        }
    }
}
