<?php

namespace App\Http\Controllers;

use App\Finance\Ledger;
use App\Finance\Money;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journals: everything posted, manual entries, and reversals.
 */
class JournalController extends Controller
{
    /** Journals that may be reversed by hand; the rest belong to their document. */
    public const REVERSIBLE = [Journal::MANUAL, 'money_in', 'money_out', 'transfer'];

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfMonth());
        $search = trim((string) $request->query('search', ''));

        $journals = Journal::query()
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhere('memo', 'like', "%{$search}%")
                ->orWhere('source_reference', 'like', "%{$search}%")))
            ->withSum('lines', 'debit_minor')
            ->orderByDesc('entry_date')
            ->orderByDesc('number')
            ->limit(500)
            ->get();

        return Inertia::render('journals/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'search' => $search,
            'journals' => $journals->map(fn (Journal $journal) => [
                'id' => $journal->id,
                'number' => $journal->number,
                'date' => $journal->entry_date->toDateString(),
                'memo' => $journal->memo,
                'source' => $journal->source_app,
                'sourceType' => $journal->source_type,
                'sourceReference' => $journal->source_reference,
                'totalMinor' => (int) $journal->getAttribute('lines_sum_debit_minor'),
                'reversed' => $journal->reversed_by_id !== null,
                'reversal' => $journal->reversal_of_id !== null,
            ]),
        ]);
    }

    public function show(Journal $journal): Response
    {
        $journal->load(['lines.account', 'creator', 'reversalOf', 'reversedBy']);

        return Inertia::render('journals/show', [
            'journal' => [
                'id' => $journal->id,
                'number' => $journal->number,
                'date' => $journal->entry_date->toDateString(),
                'memo' => $journal->memo,
                'source' => $journal->source_app,
                'sourceType' => $journal->source_type,
                'sourceReference' => $journal->source_reference,
                'createdBy' => $journal->creator?->name,
                'createdAt' => $journal->created_at->toIso8601String(),
                'reversalOf' => $journal->reversalOf === null ? null : ['id' => $journal->reversalOf->id, 'number' => $journal->reversalOf->number],
                'reversedBy' => $journal->reversedBy === null ? null : ['id' => $journal->reversedBy->id, 'number' => $journal->reversedBy->number],
                'reversible' => $journal->reversed_by_id === null && $journal->reversal_of_id === null && in_array($journal->source_type, self::REVERSIBLE, true),
                'lines' => $journal->lines->map(fn (JournalLine $line) => [
                    'id' => $line->id,
                    'accountId' => $line->account_id,
                    'account' => $line->account->label(),
                    'description' => $line->description,
                    'debitMinor' => $line->debit_minor,
                    'creditMinor' => $line->credit_minor,
                ]),
                'totalMinor' => $journal->total(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('journals/create', [
            'accounts' => $this->accountOptions(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'uuid', $this->openAccount()],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'string', Money::rule()],
            'lines.*.credit' => ['nullable', 'string', Money::rule()],
        ]);

        $accounts = Account::query()->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $debit = ($line['debit'] ?? '') === '' ? 0 : Money::toMinor($line['debit']);
            $credit = ($line['credit'] ?? '') === '' ? 0 : Money::toMinor($line['credit']);
            $account = $accounts->get($line['account_id']);

            if ($account === null) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Choose an account.']);
            }

            $lines[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $line['description'] ?? null];
        }

        $journal = $ledger->post(CarbonImmutable::parse($data['entry_date']), $data['memo'], $lines, [], $this->user($request));

        return redirect()->route('journals.show', $journal)->with('success', "Journal {$journal->number} posted.");
    }

    public function reverse(Request $request, Journal $journal, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        if (! in_array($journal->source_type, self::REVERSIBLE, true)) {
            throw ValidationException::withMessages(['journal' => "{$journal->number} belongs to its document; correct it there."]);
        }

        $reversal = $ledger->reverse($journal, CarbonImmutable::parse($data['entry_date']), "Reversal of {$journal->number}: {$data['reason']}", $this->user($request));

        return redirect()->route('journals.show', $reversal)->with('success', "{$journal->number} reversed by {$reversal->number}.");
    }
}
