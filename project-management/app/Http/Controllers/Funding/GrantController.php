<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Grants;
use App\Enums\ExpenseStatus;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Funder;
use App\Models\FunderContact;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantAmendment;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use App\Models\Invitation;
use App\Models\Project;
use App\Support\CsvExport;
use App\Support\DonorProjection;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Grants from opportunity to closeout: the pipeline, one grant's
 * agreement, its award, amendments and closeout. Allocations, payments and
 * reports: GrantFundingController. The money rules: App\Actions\Grants.
 */
class GrantController extends Controller
{
    /**
     * Every grant as CSV. Grants differ in currency, so each amount is written out in its own.
     */
    public function export(): StreamedResponse
    {
        $grants = Grant::query()->with('funder:id,name')->orderBy('reference')->get();

        return CsvExport::download('grants', [
            'Reference' => 'reference',
            'Title' => 'title',
            'Funder' => 'funder',
            'Status' => 'status',
            'Restricted' => 'restricted',
            'Starts' => 'startOn',
            'Ends' => 'endOn',
            'Currency' => 'currency',
            'Amount' => 'amount',
            'Received' => 'received',
        ], $grants->map(fn (Grant $grant) => [
            'reference' => $grant->reference,
            'title' => $grant->title,
            'funder' => $grant->funder->name,
            'status' => $grant->status->value,
            'restricted' => $grant->restricted,
            'startOn' => $grant->start_on?->toDateString(),
            'endOn' => $grant->end_on?->toDateString(),
            'currency' => $grant->currency,
            'amount' => Money::toMajor($grant->amount_minor, $grant->currency),
            'received' => Money::toMajor($grant->receivedMinor(), $grant->currency),
        ]));
    }

    public function index(Request $request): Response
    {
        $status = GrantStatus::tryFrom($request->string('status')->toString());
        $grants = Grant::query()->with('funder:id,name')
            ->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum(['disbursements as received_minor' => fn ($query) => $query->whereNotNull('received_on')], 'amount_minor')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByRaw("case status when 'active' then 0 when 'applied' then 1 when 'opportunity' then 2 else 3 end")
            ->orderBy('end_on')->orderBy('reference')
            ->get();

        $reports = GrantReport::query()->with('grant:id,reference,title')
            ->whereIn('status', [GrantReportStatus::Due, GrantReportStatus::RevisionRequested])
            ->where('due_on', '<=', today()->addDays(60))
            ->orderBy('due_on')->limit(20)->get();

        // Active funding by currency: grants are not converted for a total.
        $active = Grant::query()->where('status', GrantStatus::Active)->get(['currency', 'amount_minor']);

        return Inertia::render('portal/funding/grants/index', [
            'grants' => $grants->map(fn (Grant $grant) => [
                'id' => $grant->id,
                'reference' => $grant->reference,
                'title' => $grant->title,
                'funder' => $grant->funder->name,
                'status' => $grant->status->value,
                'currency' => $grant->currency,
                'amountMinor' => $grant->amount_minor,
                'allocatedMinor' => (int) $grant->getAttribute('allocated_minor'),
                'receivedMinor' => (int) $grant->getAttribute('received_minor'),
                'endOn' => $grant->end_on?->toDateString(),
            ]),
            'activeTotals' => $active->groupBy('currency')
                ->map(fn (Collection $rows, string $currency) => ['currency' => $currency, 'amountMinor' => (int) $rows->sum('amount_minor'), 'grants' => $rows->count()])
                ->values(),
            'reportsDue' => $reports->map(fn (GrantReport $report) => [
                'id' => $report->id,
                'grantId' => $report->grant_id,
                'grant' => $report->grant->reference,
                'title' => $report->title,
                'dueOn' => $report->due_on->toDateString(),
                'status' => $report->status->value,
                'overdue' => $report->isOverdue(),
            ]),
            'statuses' => GrantStatus::options(),
            'filters' => ['status' => $status->value ?? ''],
            'canManage' => $this->can($request, 'grants.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $funders = Funder::options();
        // From a connected donor: its funder record comes chosen.
        $chosen = $request->query('funder');

        return Inertia::render('portal/funding/grants/form', [
            'grant' => null,
            'funders' => $funders,
            'funderId' => is_string($chosen) && collect($funders)->contains('value', $chosen) ? $chosen : null,
            'currency' => Money::currency(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in([GrantStatus::Opportunity->value, GrantStatus::Applied->value])]])['status'];
        $grant = Grants::create($this->details($request, null), GrantStatus::from($status), $this->member($request));

        return to_route('grants.show', ['grant' => $grant->id])->with('success', "{$grant->reference} recorded.");
    }

    public function show(Request $request, Grant $grant): Response
    {
        $grant->load(['funder:id,name', 'allocations.project:id,code,name,currency', 'amendments.recorder:id,name']);
        $allocated = (int) $grant->allocations->sum('amount_minor');
        $disbursements = $grant->disbursements()->get();
        $scheduled = (int) $disbursements->sum('amount_minor');
        $received = (int) $disbursements->whereNotNull('received_on')->sum('amount_minor');
        $allocatedTo = $grant->allocations->pluck('project_id');
        // Spending charged to the grant, per project, in the project's currency.
        $spending = Expense::query()->where('grant_id', $grant->id)
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->groupBy('project_id', 'status')->selectRaw('project_id, status, sum(amount_minor) as amount')
            ->get();
        $charged = fn (string $projectId, ExpenseStatus $status) => (int) $spending
            ->filter(fn (Expense $row) => $row->project_id === $projectId && $row->status === $status)
            ->sum(fn (Expense $row) => (int) $row->getAttribute('amount'));

        return Inertia::render('portal/funding/grants/show', [
            'grant' => [
                'id' => $grant->id,
                'reference' => $grant->reference,
                'title' => $grant->title,
                'description' => $grant->description,
                'funder' => $grant->funder->name,
                'status' => $grant->status->value,
                'pipeline' => $grant->status->isPipeline(),
                'currency' => $grant->currency,
                'amountMinor' => $grant->amount_minor,
                'restricted' => $grant->restricted,
                'startOn' => $grant->start_on?->toDateString(),
                'endOn' => $grant->end_on?->toDateString(),
                'appliedOn' => $grant->applied_on?->toDateString(),
                'awardedOn' => $grant->awarded_on?->toDateString(),
                'closedOn' => $grant->closed_on?->toDateString(),
                'declinedOn' => $grant->declined_on?->toDateString(),
            ],
            'totals' => [
                'allocatedMinor' => $allocated,
                'availableMinor' => max(0, $grant->amount_minor - $allocated),
                'scheduledMinor' => $scheduled,
                'receivedMinor' => $received,
                'unscheduledMinor' => max(0, $grant->amount_minor - $scheduled),
            ],
            'allocations' => $grant->allocations->sortBy('project.code')->values()->map(fn (GrantAllocation $allocation) => [
                'id' => $allocation->id,
                'projectId' => $allocation->project_id,
                'project' => "{$allocation->project->code} · {$allocation->project->name}",
                'amountMinor' => $allocation->amount_minor,
                'exchangeRate' => rtrim(rtrim($allocation->exchange_rate, '0'), '.'),
                'projectCurrency' => $allocation->project->currency,
                'projectAmountMinor' => $allocation->project_amount_minor,
                'note' => $allocation->note,
                'committedMinor' => $charged($allocation->project_id, ExpenseStatus::Approved),
                'spentMinor' => $charged($allocation->project_id, ExpenseStatus::Paid),
            ]),
            'disbursements' => $disbursements->map(fn (GrantDisbursement $disbursement) => [
                'id' => $disbursement->id,
                'dueOn' => $disbursement->due_on->toDateString(),
                'amountMinor' => $disbursement->amount_minor,
                'receivedOn' => $disbursement->received_on?->toDateString(),
                'overdue' => $disbursement->received_on === null && $disbursement->due_on->lt(today()),
                'note' => $disbursement->note,
            ]),
            'reports' => $grant->reports()->get()->map(fn (GrantReport $report) => [
                'id' => $report->id,
                'title' => $report->title,
                'kind' => GrantReport::KINDS[$report->kind] ?? $report->kind,
                'dueOn' => $report->due_on->toDateString(),
                'status' => $report->status->value,
                'next' => array_map(fn (GrantReportStatus $status) => ['value' => $status->value, 'label' => $status->label()], $report->status->next()),
                'submittedOn' => $report->submitted_on?->toDateString(),
                'acceptedOn' => $report->accepted_on?->toDateString(),
                'overdue' => $report->isOverdue(),
                'notes' => $report->notes,
            ]),
            'amendments' => $grant->amendments->map(fn (GrantAmendment $amendment) => [
                'id' => $amendment->id,
                'number' => $amendment->number,
                'effectiveOn' => $amendment->effective_on->toDateString(),
                'reason' => $amendment->reason,
                'amountBeforeMinor' => $amendment->amount_before_minor,
                'amountAfterMinor' => $amendment->amount_after_minor,
                'endBefore' => $amendment->end_before?->toDateString(),
                'endAfter' => $amendment->end_after?->toDateString(),
                'recordedBy' => $amendment->recorder->name,
            ]),
            // Projects it can still fund (not cancelled; not already funded by it).
            'projects' => Project::query()->where('status', '!=', Project::CANCELLED)->whereNotIn('id', $allocatedTo)
                ->orderBy('code')->get(['id', 'code', 'name', 'currency'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}", 'currency' => $project->currency]),
            'reportKinds' => collect(GrantReport::KINDS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'canManage' => $this->can($request, 'grants.manage'),
            'today' => today()->toDateString(),
            'portal' => [
                'enabled' => $grant->portal_enabled,
                'budget' => $grant->portal_budget,
                'spending' => $grant->portal_spending,
                'commitments' => $grant->portal_commitments,
                'progress' => $grant->portal_progress,
                'milestones' => $grant->portal_milestones,
                'tasks' => $grant->portal_tasks,
                'contacts' => FunderContact::query()->where('funder_id', $grant->funder_id)->with('user:id,name,email')->get()->map(fn (FunderContact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->user->name,
                    'email' => $contact->user->email,
                ])->values(),
                'invited' => Invitation::query()->open()->where('funder_id', $grant->funder_id)->pluck('email')->values(),
                'reports' => $grant->reports()->with('documents')->get()->map(fn (GrantReport $report) => [
                    'id' => $report->id,
                    'title' => $report->title,
                    'statusLabel' => $report->status->label(),
                    'canAttach' => $report->status !== GrantReportStatus::Accepted,
                    'canDetach' => $report->status->isOutstanding(),
                    'documents' => $report->documents->map(fn (GrantReportDocument $document) => ['id' => $document->id, 'name' => $document->original_name])->values(),
                ])->values(),
                'messages' => DonorProjection::messages($grant),
            ],
        ]);
    }

    public function edit(Grant $grant): Response
    {
        return Inertia::render('portal/funding/grants/form', [
            'grant' => [
                'id' => $grant->id,
                'funderId' => $grant->funder_id,
                'reference' => $grant->reference,
                'title' => $grant->title,
                'description' => $grant->description,
                'status' => $grant->status->value,
                'pipeline' => $grant->status->isPipeline(),
                'currency' => $grant->currency,
                'amount' => Money::toMajor($grant->amount_minor, $grant->currency),
                'restricted' => $grant->restricted,
                'startOn' => $grant->start_on?->toDateString(),
                'endOn' => $grant->end_on?->toDateString(),
            ],
            'funders' => Funder::options(),
            'currency' => $grant->currency,
        ]);
    }

    public function update(Request $request, Grant $grant): RedirectResponse
    {
        $details = $this->details($request, $grant);
        if (! $grant->status->isPipeline()) {
            // The form does not send these once awarded; amend() changes them.
            unset($details['amount_minor'], $details['currency'], $details['end_on']);
        }
        Grants::update($grant, $details, $this->member($request));

        return to_route('grants.show', ['grant' => $grant->id])->with('success', 'Grant saved.');
    }

    public function apply(Request $request, Grant $grant): RedirectResponse
    {
        Grants::apply($grant, $this->date($request, 'on'), $this->member($request));

        return back()->with('success', "{$grant->reference} is applied for.");
    }

    public function award(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($grant->currency)],
            'on' => ['required', 'date', 'before_or_equal:today'],
            'start_on' => ['nullable', 'date'],
            'end_on' => ['nullable', 'date'],
        ]);
        Grants::award(
            $grant,
            Money::toMinor($validated['amount'], $grant->currency),
            CarbonImmutable::parse($validated['on']),
            isset($validated['start_on']) ? CarbonImmutable::parse($validated['start_on']) : null,
            isset($validated['end_on']) ? CarbonImmutable::parse($validated['end_on']) : null,
            $this->member($request),
        );

        return back()->with('success', "{$grant->reference} is awarded: ".Money::format($grant->amount_minor, $grant->currency).'.');
    }

    public function decline(Request $request, Grant $grant): RedirectResponse
    {
        Grants::decline($grant, $this->date($request, 'on'), $this->member($request));

        return back()->with('success', "{$grant->reference} is recorded as declined.");
    }

    public function close(Request $request, Grant $grant): RedirectResponse
    {
        Grants::close($grant, $this->date($request, 'on'), $this->member($request));

        return back()->with('success', "{$grant->reference} is closed.");
    }

    public function amend(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($grant->currency)],
            'end_on' => ['nullable', 'date'],
            'effective_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $amendment = Grants::amend(
            $grant,
            Money::toMinor($validated['amount'], $grant->currency),
            isset($validated['end_on']) ? CarbonImmutable::parse($validated['end_on']) : null,
            CarbonImmutable::parse($validated['effective_on']),
            trim($validated['reason']),
            $this->member($request),
        );

        return back()->with('success', "Amendment {$amendment->number} recorded.");
    }

    private function date(Request $request, string $field): CarbonImmutable
    {
        return CarbonImmutable::parse($request->validate([$field => ['required', 'date', 'before_or_equal:today']])[$field]);
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request, ?Grant $grant): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $pipeline = $grant === null || $grant->status->isPipeline();
        $request->merge([
            'reference' => mb_strtoupper(trim($request->string('reference')->toString())),
            'currency' => $pipeline ? mb_strtoupper(trim($request->string('currency')->toString())) : $grant->currency,
        ]);
        $currency = $request->string('currency')->toString();

        $validated = $request->validate([
            'funder_id' => ['required', 'uuid', Rule::exists('funders', 'id')->where('organization_id', $organizationId)],
            'reference' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9\/._-]*$/', Rule::unique('grants', 'reference')->where('organization_id', $organizationId)->ignore($grant?->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'amount' => [$pipeline ? 'required' : 'nullable', 'string', Money::rule(preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null)],
            'start_on' => ['nullable', 'date'],
            'end_on' => ['nullable', 'date', 'after_or_equal:start_on'],
        ], [
            'reference.regex' => 'Letters, digits and / . _ - only, e.g. ECHO/2026/041.',
            'reference.unique' => 'Another grant has this reference.',
            'currency.regex' => 'A three-letter currency code, e.g. USD, EUR.',
        ]);

        $details = [
            'funder_id' => $validated['funder_id'],
            'reference' => $validated['reference'],
            'title' => trim($validated['title']),
            'description' => $this->text($validated['description'] ?? null),
            'restricted' => $request->boolean('restricted'),
            'start_on' => $validated['start_on'] ?? null,
        ];

        if ($pipeline) {
            $details['currency'] = $validated['currency'];
            $details['amount_minor'] = Money::toMinor($validated['amount'], $validated['currency']);
            $details['end_on'] = $validated['end_on'] ?? null;
        }

        return $details;
    }
}
