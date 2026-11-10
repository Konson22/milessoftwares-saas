<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Spending;
use App\Enums\BudgetVersionStatus;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What waits on finance across the organization: budget versions to
 * approve, spending requests to decide, approved spending to pay. Each
 * queue shows only to those who may act on it.
 */
class ApprovalController extends Controller
{
    public const PERMISSIONS = ['finance.budgets.approve', 'finance.expenses.approve', 'finance.payments.record'];

    public function __invoke(Request $request): Response
    {
        $budgets = $this->can($request, 'finance.budgets.approve');
        $decide = $this->can($request, 'finance.expenses.approve');
        $pay = $this->can($request, 'finance.payments.record');
        abort_unless($budgets || $decide || $pay, 403);
        $me = $this->member($request);

        $row = fn (Expense $expense) => [
            'id' => $expense->id,
            'projectId' => $expense->project_id,
            'project' => "{$expense->project->code} · {$expense->project->name}",
            'currency' => $expense->project->currency,
            'category' => $expense->category->label(),
            'description' => $expense->description,
            'supplier' => $expense->supplier,
            'amountMinor' => $expense->amount_minor,
            'grant' => $expense->grant?->reference,
            'requestedBy' => $expense->recorder->name,
            'requestedAt' => $expense->created_at->toIso8601String(),
            'documents' => $expense->documents_count,
            'mine' => $expense->recorded_by === $me->id,
        ];
        $expenses = fn (ExpenseStatus $status) => Expense::query()->where('status', $status)
            ->with('project:id,code,name,currency', 'recorder:id,name', 'grant:id,reference')
            ->withCount('documents')->oldest()->limit(200)->get();

        return Inertia::render('portal/finance/approvals', [
            'budgets' => $budgets ? BudgetVersion::query()->where('status', BudgetVersionStatus::Submitted)
                ->with('project:id,code,name,currency', 'submitter:id,name')->oldest('submitted_at')->get()
                ->map(fn (BudgetVersion $version) => [
                    'id' => $version->id,
                    'projectId' => $version->project_id,
                    'project' => "{$version->project->code} · {$version->project->name}",
                    'currency' => $version->project->currency,
                    'number' => $version->number,
                    'totalMinor' => $version->total_minor,
                    'note' => $version->note,
                    'submittedBy' => $version->submitter?->name,
                    'submittedAt' => $version->submitted_at?->toIso8601String(),
                    'mine' => $version->submitted_by === $me->id,
                ]) : null,
            'requests' => $decide ? $expenses(ExpenseStatus::Requested)->load('approvals.user:id,name')->map(fn (Expense $expense) => [
                ...$row($expense),
                'availableMinor' => Spending::categoryAvailable($expense->project, $expense->category),
                'approvalsRequired' => $expense->approvals_required,
                'approvedBy' => $expense->approvals->map(fn (ExpenseApproval $approval) => $approval->user->name)->values()->all(),
                'approvedByMe' => $expense->approvals->contains('user_id', $me->id),
            ]) : null,
            'payments' => $pay ? $expenses(ExpenseStatus::Approved)->map($row) : null,
            'today' => today()->toDateString(),
        ]);
    }
}
