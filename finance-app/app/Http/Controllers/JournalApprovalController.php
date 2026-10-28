<?php

namespace App\Http\Controllers;

use App\Finance\JournalApprovals;
use App\Finance\Money;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\JournalDraft;
use App\Models\RecurringJournal;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journal approvals: manual journals waiting for a second person, the
 * latest decisions, and the organization's approval setting.
 */
class JournalApprovalController extends Controller
{
    public function index(Request $request, JournalApprovals $approvals): Response
    {
        $me = $this->user($request)->id;
        $pending = JournalDraft::query()->with('submitter')->where('status', JournalDraft::PENDING)->orderBy('created_at')->get();
        $decided = JournalDraft::query()->with(['submitter', 'reviewer', 'journal'])->where('status', '!=', JournalDraft::PENDING)->orderByDesc('reviewed_at')->limit(50)->get();

        $lines = collect([...$pending, ...$decided])->flatMap(fn (JournalDraft $draft) => $draft->lines);
        $accounts = Account::query()->whereIn('id', $lines->pluck('account_id')->unique()->all())->get()->keyBy('id');
        $values = DimensionValue::query()->whereIn('id', $lines->pluck('cost_centre_id')->merge($lines->pluck('project_id'))->filter()->unique()->all())->get()->keyBy('id');

        $row = fn (JournalDraft $draft) => [
            'id' => $draft->id,
            'number' => $draft->number,
            'date' => $draft->entry_date->toDateString(),
            'memo' => $draft->memo,
            'totalMinor' => $draft->total_minor,
            'status' => $draft->status,
            'submittedBy' => $draft->submitter->name,
            'submittedAt' => $draft->created_at->toIso8601String(),
            'own' => $draft->submitted_by === $me,
            'reviewedBy' => $draft->reviewer?->name,
            'reviewedAt' => $draft->reviewed_at?->toIso8601String(),
            'reviewNote' => $draft->review_note,
            'journal' => $draft->journal === null ? null : ['id' => $draft->journal->id, 'number' => $draft->journal->number],
            'lines' => array_map(fn (array $line) => [
                'account' => $accounts->get($line['account_id'])?->label() ?? 'Account removed',
                'description' => $line['description'] ?? null,
                'debitMinor' => (int) $line['debit_minor'],
                'creditMinor' => (int) $line['credit_minor'],
                'dimensions' => implode(' · ', array_filter([
                    isset($line['cost_centre_id']) ? $values->get($line['cost_centre_id'])?->label() : null,
                    isset($line['project_id']) ? $values->get($line['project_id'])?->label() : null,
                ])),
            ], $draft->lines),
        ];

        $workspace = Workspace::current();

        return Inertia::render('journal-approvals/index', [
            'pending' => $pending->map($row)->values(),
            'decided' => $decided->map($row)->values(),
            'setting' => [
                'enabled' => $workspace->journal_approval ?? false,
                'threshold' => $workspace?->journal_approval_threshold_minor === null ? '' : Money::toMajor($workspace->journal_approval_threshold_minor),
            ],
            'recurringAwaiting' => RecurringJournal::query()->where('active', true)->get()->reject(fn (RecurringJournal $template) => $approvals->recurringCleared($template))->count(),
        ]);
    }

    public function approve(Request $request, JournalDraft $draft, JournalApprovals $approvals): RedirectResponse
    {
        $journal = $approvals->approve($draft, $this->user($request));

        return back()->with('success', "{$draft->number} approved and posted as {$journal->number}.");
    }

    public function reject(Request $request, JournalDraft $draft, JournalApprovals $approvals): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $approvals->reject($draft, $data['reason'], $this->user($request));

        return back()->with('success', "{$draft->number} rejected.");
    }

    public function withdraw(Request $request, JournalDraft $draft, JournalApprovals $approvals): RedirectResponse
    {
        $approvals->withdraw($draft, $this->user($request));

        return back()->with('success', "{$draft->number} withdrawn.");
    }

    public function settings(Request $request, JournalApprovals $approvals): RedirectResponse
    {
        $data = $request->validate([
            'journal_approval' => ['required', 'boolean'],
            'threshold' => ['nullable', 'string', Money::rule()],
        ]);
        $enabled = (bool) $data['journal_approval'];
        $threshold = ($data['threshold'] ?? '') === '' ? null : Money::toMinor($data['threshold']);
        $approvals->configure($enabled, $threshold);

        return back()->with('success', match (true) {
            ! $enabled => 'Manual journals post without approval.',
            $threshold === null => 'Every manual journal now needs a second person\'s approval.',
            default => 'Manual journals of '.Money::toMajor($threshold).' or more now need a second person\'s approval.',
        });
    }
}
