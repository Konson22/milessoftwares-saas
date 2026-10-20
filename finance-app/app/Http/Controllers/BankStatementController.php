<?php

namespace App\Http\Controllers;

use App\Finance\Money;
use App\Finance\Reconciliation;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank statements: import, matching to the ledger, and reconciliation.
 */
class BankStatementController extends Controller
{
    /** Unmatched lines that get ledger suggestions on the statement page. */
    private const SUGGESTED_LINES = 200;

    public function index(Reconciliation $reconciliation): Response
    {
        $banks = Account::query()->banks()->where('active', true)->orderBy('code')->get();
        $statements = BankStatement::query()
            ->with('account')
            ->withCount(['lines', 'lines as unmatched_count' => fn ($query) => $query->whereNull('journal_line_id')])
            ->orderByDesc('ends_on')
            ->limit(200)
            ->get();

        return Inertia::render('reconciliation/index', [
            'banks' => $banks->map(function (Account $bank) use ($reconciliation) {
                $latest = $reconciliation->latest($bank);

                return [
                    'id' => $bank->id,
                    'label' => $bank->label(),
                    'lastEndsOn' => $latest?->ends_on->toDateString(),
                    'lastClosingMinor' => $latest?->closing_balance_minor,
                    'import' => $bank->bank_import,
                ];
            }),
            'statements' => $statements->map(fn (BankStatement $statement) => [
                'id' => $statement->id,
                'account' => $statement->account->label(),
                'reference' => $statement->reference,
                'startsOn' => $statement->starts_on->toDateString(),
                'endsOn' => $statement->ends_on->toDateString(),
                'openingMinor' => $statement->opening_balance_minor,
                'closingMinor' => $statement->closing_balance_minor,
                'lines' => (int) $statement->getAttribute('lines_count'),
                'unmatched' => (int) $statement->getAttribute('unmatched_count'),
                'reconciled' => $statement->reconciled(),
            ]),
        ]);
    }

    public function store(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
            'opening_balance' => ['nullable', 'string', Money::signedRule()],
            'closing_balance' => ['required', 'string', Money::signedRule()],
            'rows' => ['present', 'array', 'max:5000'],
            'rows.*.date' => ['required', 'date_format:Y-m-d'],
            'rows.*.description' => ['required', 'string', 'max:500'],
            'rows.*.reference' => ['nullable', 'string', 'max:200'],
            'rows.*.amount' => ['required', 'string', Money::signedRule()],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
        ]);

        $bank = Account::query()->whereKey($data['account_id'])->firstOrFail();
        $rows = [];
        foreach ($data['rows'] as $row) {
            $amount = Money::toSignedMinor($row['amount']);
            if ($amount !== 0) {
                $rows[] = [
                    'date' => CarbonImmutable::parse($row['date']),
                    'description' => $row['description'],
                    'reference' => ($row['reference'] ?? '') === '' ? null : $row['reference'],
                    'amount' => $amount,
                ];
            }
        }

        $result = $reconciliation->import(
            $bank,
            CarbonImmutable::parse($data['ends_on']),
            ($data['opening_balance'] ?? '') === '' ? null : Money::toSignedMinor($data['opening_balance']),
            Money::toSignedMinor($data['closing_balance']),
            $data['reference'] ?? null,
            $rows,
            $this->user($request),
        );

        if (isset($data['mapping'])) {
            $bank->forceFill(['bank_import' => $data['mapping']])->save();
        }

        $count = $result['statement']->lines()->count();

        return redirect()->route('reconciliation.show', $result['statement'])->with('success', "Statement imported: {$count} ".($count === 1 ? 'line' : 'lines')
            .($result['skipped'] > 0 ? "; {$result['skipped']} already on the previous statement skipped." : '.'));
    }

    public function show(BankStatement $statement, Reconciliation $reconciliation): Response
    {
        $statement->load(['account', 'lines.journalLine.journal', 'reconciler', 'importer']);
        $rules = BankRule::query()->with('account')->orderBy('position')->get();
        $suggested = 0;

        return Inertia::render('reconciliation/show', [
            'statement' => [
                'id' => $statement->id,
                'account' => $statement->account->label(),
                'accountId' => $statement->account_id,
                'reference' => $statement->reference,
                'startsOn' => $statement->starts_on->toDateString(),
                'endsOn' => $statement->ends_on->toDateString(),
                'openingMinor' => $statement->opening_balance_minor,
                'closingMinor' => $statement->closing_balance_minor,
                'linesMinor' => (int) $statement->lines->sum('amount_minor'),
                'reconciledAt' => $statement->reconciled_at?->toIso8601String(),
                'reconciledBy' => $statement->reconciler?->name,
                'importedBy' => $statement->importer->name,
                'latest' => $reconciliation->latest($statement->account)?->id === $statement->id,
            ],
            'lines' => $statement->lines->map(function (BankStatementLine $line) use ($reconciliation, $rules, &$suggested, $statement) {
                $open = $line->journal_line_id === null && ! $statement->reconciled();
                $candidates = $open && $suggested++ < self::SUGGESTED_LINES ? $reconciliation->candidates($line) : collect();
                $rule = $open ? $rules->first(fn (BankRule $rule) => $rule->applies($line)) : null;

                return [
                    'id' => $line->id,
                    'date' => $line->line_date->toDateString(),
                    'description' => $line->description,
                    'reference' => $line->reference,
                    'amountMinor' => $line->amount_minor,
                    'match' => $line->journalLine === null ? null : [
                        'journalId' => $line->journalLine->journal_id,
                        'number' => $line->journalLine->journal->number,
                        'date' => $line->journalLine->journal->entry_date->toDateString(),
                        'memo' => $line->journalLine->journal->memo,
                    ],
                    'candidates' => $candidates->map(fn (JournalLine $candidate) => [
                        'id' => $candidate->id,
                        'journalId' => $candidate->journal_id,
                        'number' => (string) $candidate->getAttribute('number'),
                        'date' => substr((string) $candidate->getAttribute('entry_date'), 0, 10),
                        'memo' => (string) $candidate->getAttribute('memo'),
                    ])->values(),
                    'rule' => $rule === null ? null : ['accountId' => $rule->account_id, 'account' => $rule->account->label(), 'memo' => $rule->memo],
                ];
            }),
            'accounts' => $this->accountOptions(bank: false),
            'report' => $reconciliation->report($statement),
        ]);
    }

    public function match(Request $request, BankStatementLine $line, Reconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate(['journal_line_id' => ['required', 'uuid', $this->ours('journal_lines')]]);

        $reconciliation->match($line, JournalLine::query()->whereKey($data['journal_line_id'])->firstOrFail(), $this->user($request));

        return back()->with('success', 'Line matched.');
    }

    public function unmatch(BankStatementLine $line, Reconciliation $reconciliation): RedirectResponse
    {
        $reconciliation->unmatch($line);

        return back()->with('success', 'Line unmatched.');
    }

    public function enter(Request $request, BankStatementLine $line, Reconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: false)],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $line = $reconciliation->enter($line, Account::query()->whereKey($data['account_id'])->firstOrFail(), ($data['memo'] ?? '') === '' ? null : $data['memo'], $this->user($request));

        return back()->with('success', 'Entered in the books and matched'.($line->journalLine ? " ({$line->journalLine->journal->number})." : '.'));
    }

    public function auto(Request $request, BankStatement $statement, Reconciliation $reconciliation): RedirectResponse
    {
        $result = $reconciliation->autoMatch($statement, $this->user($request));

        return back()->with('success', "{$result['matched']} matched, {$result['entered']} entered by rules.");
    }

    public function reconcile(Request $request, BankStatement $statement, Reconciliation $reconciliation): RedirectResponse
    {
        $reconciliation->reconcile($statement, $this->user($request));

        return back()->with('success', 'Statement reconciled.');
    }

    public function undo(BankStatement $statement, Reconciliation $reconciliation): RedirectResponse
    {
        $reconciliation->undo($statement);

        return back()->with('success', 'Reconciliation undone.');
    }

    public function destroy(BankStatement $statement, Reconciliation $reconciliation): RedirectResponse
    {
        $reconciliation->delete($statement);

        return redirect()->route('reconciliation.index')->with('success', 'Statement removed.');
    }
}
