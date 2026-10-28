<?php

namespace App\Http\Controllers;

use App\Finance\JournalApprovals;
use App\Finance\Money;
use App\Finance\RecurringJournals;
use App\Models\Journal;
use App\Models\RecurringJournal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recurring journals: templates posted on a schedule.
 */
class RecurringJournalController extends Controller
{
    public function index(Request $request, JournalApprovals $approvals): Response
    {
        $today = CarbonImmutable::today();
        $me = $this->user($request)->id;

        return Inertia::render('recurring-journals/index', [
            'templates' => RecurringJournal::query()->with(['lastJournal', 'approver'])->orderBy('name')->get()->map(fn (RecurringJournal $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'memo' => $template->memo,
                'frequency' => RecurringJournal::FREQUENCIES[$template->frequency] ?? $template->frequency,
                'startsOn' => $template->starts_on->toDateString(),
                'endsOn' => $template->ends_on?->toDateString(),
                'nextOn' => $template->next_on?->toDateString(),
                'due' => $template->active && $template->next_on !== null && $template->next_on->lessThanOrEqualTo($today),
                'postedCount' => $template->posted_count,
                'reverses' => $template->reverses,
                'active' => $template->active,
                'totalMinor' => $template->total(),
                'lastJournal' => $template->lastJournal === null ? null : ['id' => $template->lastJournal->id, 'number' => $template->lastJournal->number, 'date' => $template->lastJournal->entry_date->toDateString()],
                'lastError' => $template->last_error,
                // Waiting for a second person (journal approval is on).
                'awaitingApproval' => ! $approvals->recurringCleared($template),
                'ownChange' => ($template->updated_by ?? $template->created_by) === $me,
                'approvedBy' => $template->approver?->name,
            ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(RecurringJournal $recurringJournal): Response
    {
        return $this->form($recurringJournal);
    }

    public function store(Request $request, RecurringJournals $recurring): RedirectResponse
    {
        $template = $recurring->save(null, $this->validated($request), $this->user($request));

        return redirect()->route('recurring-journals.index')->with('success', "Recurring journal “{$template->name}” saved; next on {$template->next_on?->format('j M Y')}.");
    }

    public function update(Request $request, RecurringJournal $recurringJournal, RecurringJournals $recurring): RedirectResponse
    {
        $template = $recurring->save($recurringJournal, $this->validated($request), $this->user($request));

        return redirect()->route('recurring-journals.index')->with('success', "Recurring journal “{$template->name}” saved.");
    }

    public function toggle(RecurringJournal $recurringJournal, RecurringJournals $recurring): RedirectResponse
    {
        $template = $recurring->setActive($recurringJournal, ! $recurringJournal->active);

        return back()->with('success', $template->active ? "“{$template->name}” resumed." : "“{$template->name}” paused.");
    }

    public function approve(Request $request, RecurringJournal $recurringJournal, JournalApprovals $approvals): RedirectResponse
    {
        $template = $approvals->approveRecurring($recurringJournal, $this->user($request));

        return back()->with('success', "“{$template->name}” approved; it posts when due.");
    }

    public function run(Request $request, RecurringJournal $recurringJournal, RecurringJournals $recurring): RedirectResponse
    {
        $result = $recurring->run($recurringJournal, CarbonImmutable::today(), $this->user($request));

        // What posted before the refusal stays posted (one transaction each).
        if ($result['error'] !== null) {
            throw ValidationException::withMessages(['run' => ($result['posted'] > 0 ? "{$result['posted']} posted, then: " : 'Not posted: ').$result['error']]);
        }

        return back()->with('success', $result['posted'] === 0 ? 'Nothing is due yet.' : "{$result['posted']} ".($result['posted'] === 1 ? 'journal' : 'journals').' posted.');
    }

    public function destroy(RecurringJournal $recurringJournal): RedirectResponse
    {
        // The journals it posted stay; they just no longer point to it.
        $recurringJournal->delete();

        return redirect()->route('recurring-journals.index')->with('success', "Recurring journal “{$recurringJournal->name}” removed. The journals it posted stay.");
    }

    private function form(?RecurringJournal $template): Response
    {
        $money = fn (int $minor) => $minor === 0 ? '' : Money::toMajor($minor);

        return Inertia::render('recurring-journals/form', [
            'template' => $template === null ? null : [
                'id' => $template->id,
                'name' => $template->name,
                'memo' => $template->memo,
                'frequency' => $template->frequency,
                'startsOn' => $template->starts_on->toDateString(),
                'endsOn' => $template->ends_on?->toDateString(),
                'reverses' => $template->reverses,
                'postedCount' => $template->posted_count,
                'lines' => array_map(fn (array $line) => [
                    'accountId' => $line['account_id'],
                    'description' => $line['description'] ?? '',
                    'debit' => $money((int) $line['debit_minor']),
                    'credit' => $money((int) $line['credit_minor']),
                    'costCentreId' => $line['cost_centre_id'] ?? '',
                    'projectId' => $line['project_id'] ?? '',
                ], $template->lines),
                'journals' => $template->journals()->where('source_type', Journal::RECURRING)->orderByDesc('entry_date')->limit(24)->get()->map(fn (Journal $journal) => [
                    'id' => $journal->id,
                    'number' => $journal->number,
                    'date' => $journal->entry_date->toDateString(),
                ]),
            ],
            'frequencies' => collect(RecurringJournal::FREQUENCIES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'accounts' => $this->accountOptions(),
            'dimensions' => $this->dimensionOptions(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /**
     * @return array{name: string, memo: string, frequency: string, starts_on: CarbonImmutable, ends_on: CarbonImmutable|null, reverses: bool, lines: list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'memo' => ['required', 'string', 'max:200'],
            'frequency' => ['required', Rule::in(array_keys(RecurringJournal::FREQUENCIES))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reverses' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'uuid', $this->openAccount()],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'string', Money::rule()],
            'lines.*.credit' => ['nullable', 'string', Money::rule()],
            ...$this->lineDimensionRules(),
        ]);

        return [
            'name' => trim($data['name']),
            'memo' => trim($data['memo']),
            'frequency' => $data['frequency'],
            'starts_on' => CarbonImmutable::parse($data['starts_on']),
            'ends_on' => isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on']) : null,
            'reverses' => (bool) ($data['reverses'] ?? false),
            'lines' => $this->storedLines($data['lines']),
        ];
    }
}
