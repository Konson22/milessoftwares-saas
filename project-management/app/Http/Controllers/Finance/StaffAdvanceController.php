<?php

namespace App\Http\Controllers\Finance;

use App\Actions\ProjectBooks;
use App\Actions\StaffAdvances;
use App\Enums\CostCategory;
use App\Http\Controllers\Controller;
use App\Models\AdvanceRetirement;
use App\Models\AdvanceRetirementLine;
use App\Models\AdvanceSettlement;
use App\Models\Project;
use App\Models\StaffAdvance;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff advances: asking for one, paying it out, accounting for it. Everyone
 * sees their own; those who may view advances see everyone's, by age.
 */
class StaffAdvanceController extends Controller
{
    private const AGES = [
        'notDue' => 'Not yet due',
        'days30' => '1 to 30 days late',
        'days60' => '31 to 60 days late',
        'days90' => '61 to 90 days late',
        'over90' => 'Over 90 days late',
    ];

    public function __construct(private readonly StaffAdvances $advances) {}

    public function index(Request $request): Response
    {
        $viewAll = $this->can($request, 'finance.advances.view');
        abort_unless($viewAll || $this->can($request, 'finance.advances.request'), 403);
        $me = $this->member($request)->id;
        $today = CarbonImmutable::today();

        $query = StaffAdvance::query()->with('holder:id,name')->orderByDesc('number');
        $holder = $request->string('holder')->toString();
        $all = $viewAll ? (clone $query)->when($holder !== '', fn ($q) => $q->where('holder_id', $holder))->limit(300)->get() : collect();
        $mine = (clone $query)->where('holder_id', $me)->limit(100)->get();

        $row = fn (StaffAdvance $advance) => [
            'id' => $advance->id,
            'reference' => $advance->reference(),
            'holderId' => $advance->holder_id,
            'holder' => $advance->holder->name,
            'purpose' => $advance->purpose,
            'status' => $advance->status,
            'amountMinor' => $advance->amount_minor,
            'outstandingMinor' => $this->advances->outstanding($advance),
            'currency' => $advance->currency,
            'dueOn' => $advance->due_on?->toDateString(),
            'daysOverdue' => $advance->daysOverdue($today),
        ];

        $aging = null;
        if ($viewAll) {
            $buckets = array_fill_keys(array_keys(self::AGES), 0);
            $out = StaffAdvance::query()->where('status', StaffAdvance::DISBURSED)->get();
            foreach ($out as $advance) {
                $days = $advance->daysOverdue($today);
                $bucket = match (true) {
                    $days === 0 => 'notDue',
                    $days <= 30 => 'days30',
                    $days <= 60 => 'days60',
                    $days <= 90 => 'days90',
                    default => 'over90',
                };
                $buckets[$bucket] += $this->advances->outstanding($advance);
            }
            $aging = [
                'buckets' => collect(self::AGES)->map(fn (string $label, string $key) => ['label' => $label, 'amountMinor' => $buckets[$key]])->values()->all(),
                'totalMinor' => array_sum($buckets),
            ];
        }

        $books = app(CurrentOrganization::class)->require();

        return Inertia::render('portal/finance/advances/index', [
            'mine' => $mine->map($row),
            'all' => $all->map($row),
            'aging' => $aging,
            'holderFilter' => $holder === '' ? null : $holder,
            'currency' => Money::currency(),
            'maxMinor' => $books->advance_max_minor,
            'retireDays' => $books->advance_retire_days,
            'canRequest' => $this->can($request, 'finance.advances.request')
                && ! StaffAdvance::query()->where('holder_id', $me)->whereIn('status', StaffAdvance::OPEN)->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule()],
            'purpose' => ['required', 'string', 'max:255'],
        ]);

        $advance = $this->advances->request(Money::toMinor((string) $validated['amount']), trim((string) $validated['purpose']), $this->member($request));

        return to_route('advances.show', $advance)->with('success', "{$advance->reference()} sent for approval.");
    }

    public function show(Request $request, StaffAdvance $advance): Response
    {
        $this->requireHolderOrViewer($request, $advance);
        $advance->load('holder:id,name', 'decider:id,name', 'disburser:id,name');
        $me = $this->member($request)->id;
        $isHolder = $advance->holder_id === $me;
        $approver = $this->can($request, 'finance.advances.approve') && ! $isHolder;
        $disburser = $this->can($request, 'finance.advances.disburse') && ! $isHolder;
        $out = $advance->status === StaffAdvance::DISBURSED;

        return Inertia::render('portal/finance/advances/show', [
            'advance' => [
                'id' => $advance->id,
                'reference' => $advance->reference(),
                'holder' => $advance->holder->name,
                'purpose' => $advance->purpose,
                'status' => $advance->status,
                'amountMinor' => $advance->amount_minor,
                'outstandingMinor' => $this->advances->outstanding($advance),
                'currency' => $advance->currency,
                'requestedAt' => $advance->created_at->toIso8601String(),
                'decidedBy' => $advance->decider?->name,
                'decidedAt' => $advance->decided_at?->toIso8601String(),
                'decisionNote' => $advance->decision_note,
                'disbursedBy' => $advance->disburser?->name,
                'disbursedOn' => $advance->disbursed_on?->toDateString(),
                'source' => $this->methodLabel($advance->payment_method),
                'paymentReference' => $advance->reference,
                'dueOn' => $advance->due_on?->toDateString(),
                'daysOverdue' => $advance->daysOverdue(CarbonImmutable::today()),
            ],
            'retirements' => $advance->retirements()->with('decider:id,name', 'lines.project:id,code')->orderBy('created_at')->get()
                ->map(fn (AdvanceRetirement $retirement) => [
                    'id' => $retirement->id,
                    'status' => $retirement->status,
                    'returnedReason' => $retirement->returned_reason,
                    'decidedBy' => $retirement->decider?->name,
                    'totalMinor' => (int) $retirement->lines->sum('amount_minor'),
                    'canDecide' => $retirement->status === AdvanceRetirement::SUBMITTED && $approver && $out,
                    'lines' => $retirement->lines->map(fn (AdvanceRetirementLine $line) => [
                        'id' => $line->id,
                        'spentOn' => $line->spent_on->toDateString(),
                        'description' => $line->description,
                        'payee' => $line->payee,
                        'chargedTo' => $line->project !== null ? "{$line->project->code}: {$line->category?->label()}" : $line->category?->label(),
                        'amountMinor' => $line->amount_minor,
                        'receiptName' => $line->receipt_name,
                        'canRemove' => $retirement->status === AdvanceRetirement::DRAFT && $isHolder && $out,
                    ])->values()->all(),
                ]),
            'settlements' => $advance->settlements()->with('recorder:id,name', 'decider:id,name')->orderBy('created_at')->get()
                ->map(fn (AdvanceSettlement $settlement) => [
                    'id' => $settlement->id,
                    'type' => $settlement->type,
                    'status' => $settlement->status,
                    'amountMinor' => $settlement->amount_minor,
                    'on' => $settlement->on->toDateString(),
                    'reason' => $settlement->reason,
                    'recordedBy' => $settlement->recorder->name,
                    'decidedBy' => $settlement->decider?->name,
                    'decisionNote' => $settlement->decision_note,
                    'account' => $this->methodLabel($settlement->payment_method),
                    'canDecide' => $settlement->status === AdvanceSettlement::SUBMITTED && $approver && $settlement->recorded_by !== $me && $out,
                ]),
            'projects' => Project::query()->whereIn('status', Project::OPEN)->where('currency', $advance->currency)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"])->values()->all(),
            'categories' => collect(CostCategory::cases())->reject(fn (CostCategory $category) => $category === CostCategory::Labour)
                ->map(fn (CostCategory $category) => ['value' => $category->value, 'label' => $category->label()])->values()->all(),
            'methods' => ProjectBooks::methodOptions(),
            'can' => [
                'decide' => $advance->status === StaffAdvance::REQUESTED && $approver,
                'cancel' => in_array($advance->status, [StaffAdvance::REQUESTED, StaffAdvance::APPROVED], true) && $isHolder,
                'disburse' => $advance->status === StaffAdvance::APPROVED && $disburser,
                'addReceipts' => $out && $isHolder,
                'settle' => $out && $disburser,
            ],
        ]);
    }

    public function cancel(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $user = $this->member($request);
        abort_unless($advance->holder_id === $user->id, 403);

        $this->advances->cancel($advance, $user);

        return back()->with('success', "{$advance->reference()} cancelled.");
    }

    public function approve(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->advances->approve($advance, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', "{$advance->reference()} approved.");
    }

    public function reject(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->advances->reject($advance, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', "{$advance->reference()} rejected.");
    }

    public function disburse(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(ProjectBooks::METHODS)],
            'on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $this->advances->disburse(
            $advance,
            (string) $validated['payment_method'],
            CarbonImmutable::parse((string) $validated['on']),
            $this->text($validated['reference'] ?? null),
            $this->member($request),
        );

        return back()->with('success', "{$advance->reference()} paid out.");
    }

    public function addLine(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'string', Money::rule($advance->currency)],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'category' => ['required', Rule::enum(CostCategory::class)->except([CostCategory::Labour])],
            'receipt' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $projectId = $validated['project_id'] ?? null;

        $this->advances->addLine($advance, [
            'spent_on' => CarbonImmutable::parse((string) $validated['spent_on']),
            'amount_minor' => Money::toMinor((string) $validated['amount'], $advance->currency),
            'description' => trim((string) $validated['description']),
            'payee' => $this->text($validated['payee'] ?? null),
            'project' => $projectId === null ? null : Project::query()->findOrFail((string) $projectId),
            'category' => CostCategory::from((string) $validated['category']),
        ], $request->file('receipt'), $this->member($request));

        return back()->with('success', 'Receipt added. Send them when they are all in.');
    }

    public function removeLine(Request $request, StaffAdvance $advance, AdvanceRetirementLine $line): RedirectResponse
    {
        abort_unless($line->retirement->advance_id === $advance->id, 404);
        $this->advances->removeLine($line, $this->member($request));

        return back()->with('success', 'Receipt removed.');
    }

    public function receipt(Request $request, StaffAdvance $advance, AdvanceRetirementLine $line): StreamedResponse
    {
        abort_unless($line->retirement->advance_id === $advance->id, 404);
        $this->requireHolderOrViewer($request, $advance);

        return Storage::disk(StaffAdvances::DISK)->download($line->receipt_path, $line->receipt_name);
    }

    public function submitRetirement(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $this->advances->submitRetirement($advance, $this->member($request));

        return back()->with('success', 'Receipts sent for a decision.');
    }

    public function approveRetirement(Request $request, StaffAdvance $advance, AdvanceRetirement $retirement): RedirectResponse
    {
        $this->advances->approveRetirement($retirement, $this->member($request));

        return back()->with('success', 'Receipts approved: they are now expenses.');
    }

    public function returnRetirement(Request $request, StaffAdvance $advance, AdvanceRetirement $retirement): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->advances->returnRetirement($retirement, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', 'Receipts returned to be corrected.');
    }

    public function recordReturn(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($advance->currency)],
            'payment_method' => ['required', Rule::in(ProjectBooks::METHODS)],
            'on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->advances->recordReturn(
            $advance,
            Money::toMinor((string) $validated['amount'], $advance->currency),
            (string) $validated['payment_method'],
            CarbonImmutable::parse((string) $validated['on']),
            $this->text($validated['note'] ?? null),
            $this->member($request),
        );

        return back()->with('success', 'Cash received.');
    }

    public function requestWriteOff(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $validated = $request->validate([
            'write_off_amount' => ['required', 'string', Money::rule($advance->currency)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->advances->requestWriteOff($advance, Money::toMinor((string) $validated['write_off_amount'], $advance->currency), trim((string) $validated['reason']), $this->member($request));

        return back()->with('success', 'Write-off sent for a decision.');
    }

    public function approveWriteOff(Request $request, StaffAdvance $advance, AdvanceSettlement $settlement): RedirectResponse
    {
        $this->advances->approveWriteOff($settlement, CarbonImmutable::today(), $this->member($request));

        return back()->with('success', 'Written off.');
    }

    public function rejectWriteOff(Request $request, StaffAdvance $advance, AdvanceSettlement $settlement): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->advances->rejectWriteOff($settlement, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', 'Write-off rejected: it stays owed.');
    }

    /**
     * An advance is its holder's, and those who may view advances.
     */
    private function requireHolderOrViewer(Request $request, StaffAdvance $advance): void
    {
        abort_unless($advance->holder_id === $this->member($request)->id || $this->can($request, 'finance.advances.view'), 403);
    }

    private function methodLabel(?string $method): ?string
    {
        return collect(ProjectBooks::methodOptions())->firstWhere('value', $method)['label'] ?? null;
    }
}
