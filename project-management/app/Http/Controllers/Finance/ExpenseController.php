<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Spending;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Models\User;
use App\Support\CsvExport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A project's spending: requests, their approval and payment, and the
 * documents behind them. Members who may request spending but not see
 * the project's finances see only their own requests. The rules are in
 * App\Actions\Spending.
 */
class ExpenseController extends Controller
{
    use ShowsProject;

    public function index(Request $request, Project $project): Response
    {
        $finance = $this->can($request, 'projects.finance.view');
        abort_unless($finance || $this->can($request, 'projects.expenses.request'), 403);
        $me = $this->member($request);

        $expenses = $project->expenses()
            ->with('recorder:id,name', 'decider:id,name', 'grant:id,reference', 'documents')
            ->when(! $finance, fn ($query) => $query->where('recorded_by', $me->id))
            ->latest()->get();

        $canApprove = $this->can($request, 'finance.expenses.approve');
        $canPay = $this->can($request, 'finance.payments.record');
        $manage = $this->can($request, 'projects.manage');

        return Inertia::render('portal/projects/expenses', [
            'project' => $this->projectHeader($request, $project),
            'expenses' => $expenses->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'supplier' => $expense->supplier,
                'amountMinor' => $expense->amount_minor,
                'status' => $expense->status->value,
                'statusLabel' => $expense->status->label(),
                'direct' => $expense->direct,
                'grant' => $expense->grant?->reference,
                'requestedBy' => $expense->recorder->name,
                'requestedAt' => $expense->created_at->toIso8601String(),
                'decidedBy' => $expense->decider?->name,
                'decisionNote' => $expense->decision_note,
                'paidOn' => $expense->spent_on?->toDateString(),
                'paymentReference' => $expense->payment_reference,
                'documents' => $expense->documents->map(fn (ExpenseDocument $document) => [
                    'id' => $document->id,
                    'name' => $document->original_name,
                    'size' => $document->size,
                ]),
                'can' => [
                    'decide' => $canApprove && $expense->status === ExpenseStatus::Requested && $expense->recorded_by !== $me->id,
                    'pay' => $canPay && $expense->status === ExpenseStatus::Approved && $expense->recorded_by !== $me->id,
                    'cancel' => $this->mayCancel($request, $expense, $me),
                    'attach' => $this->mayAttach($request, $expense, $me),
                    'detach' => $expense->status === ExpenseStatus::Requested && ($manage || $expense->recorded_by === $me->id),
                ],
            ]),
            'finance' => $finance,
            'canRequest' => $this->can($request, 'projects.expenses.request') && $project->isOpen(),
            'canRecord' => $manage && $finance && $project->isOpen(),
            'categories' => CostCategory::options(withLabour: false),
            'grants' => $project->grantAllocations()->with('grant:id,reference,title,status')->get()
                ->filter(fn (GrantAllocation $allocation) => $allocation->grant->status->value === 'active')
                ->sortBy('grant.reference')->values()
                ->map(fn (GrantAllocation $allocation) => ['value' => $allocation->grant_id, 'label' => "{$allocation->grant->reference} · {$allocation->grant->title}"]),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * The project's spending as CSV.
     */
    public function export(Project $project): StreamedResponse
    {
        $expenses = $project->expenses()->with('recorder:id,name', 'grant:id,reference')->latest()->get();

        return CsvExport::download("{$project->code}-spending", [
            'Status' => 'status',
            'Category' => 'category',
            'Description' => 'description',
            'Supplier' => 'supplier',
            'Grant' => 'grant',
            'Requested by' => 'requestedBy',
            'Requested on' => 'requestedOn',
            'Paid on' => 'paidOn',
            'Reference' => 'reference',
            "Amount ({$project->currency})" => 'amountMinor',
        ], $expenses->map(fn (Expense $expense) => [
            'status' => $expense->status->label(),
            'category' => $expense->category->label(),
            'description' => $expense->description,
            'supplier' => $expense->supplier,
            'grant' => $expense->grant?->reference,
            'requestedBy' => $expense->recorder->name,
            'requestedOn' => $expense->created_at->toDateString(),
            'paidOn' => $expense->spent_on?->toDateString(),
            'reference' => $expense->payment_reference,
            'amountMinor' => $expense->amount_minor,
        ]), $project->currency);
    }

    /**
     * Spending to be approved.
     */
    public function request(Request $request, Project $project): RedirectResponse
    {
        Spending::request($project, $this->details($request, $project), $this->member($request));

        return back()->with('success', 'Spending requested: it waits for approval.');
    }

    /**
     * Spending already paid, written down in one step by a project manager
     * (kept from before approvals; marked direct).
     */
    public function record(Request $request, Project $project): RedirectResponse
    {
        $request->validate(['spent_on' => ['required', 'date', 'before_or_equal:today']]);

        Spending::record($project, $this->details($request, $project), CarbonImmutable::parse($request->string('spent_on')->toString()), $this->member($request));

        return back()->with('success', 'Expense recorded.');
    }

    public function approve(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $committed = Spending::approve($expense, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', $committed
            ? 'Approved: the money is committed.'
            : 'Your approval is recorded: it needs another approver before the money is committed.');
    }

    public function reject(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        Spending::reject($expense, trim($validated['reason']), $this->member($request));

        return back()->with('success', 'Request rejected.');
    }

    public function pay(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:255'],
        ]);

        Spending::pay($expense, CarbonImmutable::parse($validated['paid_on']), trim($validated['reference']), $this->member($request));

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * Cancels open spending, or voids a payment (with the reason). Nothing
     * is deleted.
     */
    public function cancel(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        abort_unless($this->mayCancel($request, $expense, $this->member($request)), 403);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        Spending::cancel($expense, $this->text($validated['reason'] ?? null), $this->member($request));

        return back()->with('success', $expense->status === ExpenseStatus::Paid ? 'Payment voided.' : 'Cancelled.');
    }

    public function attach(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        abort_unless($this->mayAttach($request, $expense, $this->member($request)), 403);
        $validated = $request->validate(['document' => ['required', ...ExpenseDocument::RULES]]);

        Spending::attach($expense, $validated['document'], $this->member($request));

        return back()->with('success', 'Document attached.');
    }

    public function detach(Request $request, Project $project, Expense $expense, ExpenseDocument $document): RedirectResponse
    {
        $me = $this->member($request);
        abort_unless($this->can($request, 'projects.manage') || $expense->recorded_by === $me->id, 403);

        Spending::detach($document, $me);

        return back()->with('success', 'Document removed.');
    }

    public function document(Request $request, Project $project, Expense $expense, ExpenseDocument $document): StreamedResponse
    {
        abort_unless($this->can($request, 'projects.finance.view') || $expense->recorded_by === $this->member($request)->id, 403);

        return Storage::disk(Spending::DISK)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{category: CostCategory, description: string, supplier: ?string, amount_minor: int, grant_id: ?string}
     */
    private function details(Request $request, Project $project): array
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(CostCategory::class), Rule::notIn([CostCategory::Labour->value])],
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule($project->currency)],
            'grant_id' => ['nullable', 'uuid'],
        ], ['category.not_in' => 'Labour comes from time logged.']);

        return [
            'category' => CostCategory::from($validated['category']),
            'description' => trim($validated['description']),
            'supplier' => $this->text($validated['supplier'] ?? null),
            'amount_minor' => Money::toMinor($validated['amount'], $project->currency),
            'grant_id' => $this->text($validated['grant_id'] ?? null),
        ];
    }

    /**
     * Open spending: whoever requested it, or a project manager. A payment:
     * whoever records payments, or a project manager for one recorded
     * directly.
     */
    private function mayCancel(Request $request, Expense $expense, User $me): bool
    {
        return match (true) {
            // Whoever approves commitments may release one nobody will pay.
            $expense->status === ExpenseStatus::Approved && $this->can($request, 'finance.expenses.approve') => true,
            $expense->status->isOpen() => $expense->recorded_by === $me->id || $this->can($request, 'projects.manage'),
            $expense->status === ExpenseStatus::Paid => $this->can($request, 'finance.payments.record')
                || ($expense->direct && $this->can($request, 'projects.manage') && $this->can($request, 'projects.finance.view')),
            default => false,
        };
    }

    private function mayAttach(Request $request, Expense $expense, User $me): bool
    {
        if (in_array($expense->status, [ExpenseStatus::Rejected, ExpenseStatus::Cancelled], true)) {
            return false;
        }

        return $expense->recorded_by === $me->id
            || $this->can($request, 'projects.manage')
            || $this->can($request, 'finance.payments.record');
    }
}
