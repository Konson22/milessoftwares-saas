<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\GrantStatus;
use App\Models\AdvanceRetirementLine;
use App\Models\ApprovalPolicy;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\GrantAllocation;
use App\Models\PettyCashPayment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The rules of spending, in one place:
 * - Spending is requested, approved (committed), then paid; or rejected or
 *   cancelled. One row through its life: nothing is deleted, and each
 *   expense counts once, as committed or as spent.
 * - Whoever requested it neither approves, rejects nor pays it. Larger
 *   amounts may need more approvers (ApprovalPolicy), each someone else.
 * - Approval needs an approved budget with enough left in the category
 *   (approved budget, less committed and spent) and, when a grant is
 *   charged, enough left of that grant's allocation to the project, on an
 *   active grant. Checks run in a transaction on the locked project.
 * - record() is the one-step path kept from before approvals: spending
 *   already paid, written down by a project manager. It is marked direct,
 *   audited and counted, but not checked against the budget.
 * - Money that moves reaches the books in the same transaction
 *   (ProjectBooks, events for Finance): paid is sent, voiding takes it back.
 * Every change is audited.
 */
final class Spending
{
    public const DISK = 'local';

    /**
     * @param  array{category: CostCategory, description: string, supplier: ?string, amount_minor: int, grant_id: ?string}  $details
     *
     * @throws ValidationException
     */
    public static function request(Project $project, array $details, User $by): Expense
    {
        self::requireSpendable($project, $details);

        return DB::transaction(function () use ($project, $details, $by) {
            $expense = $project->expenses()->create([
                ...$details,
                'status' => ExpenseStatus::Requested,
                // Fixed now: a later change of policy does not move it.
                'approvals_required' => ApprovalPolicy::approversFor($project->currency, (int) $details['amount_minor']),
                'direct' => false,
                'recorded_by' => $by->id,
            ]);
            Audit::record('expense.requested', $expense, self::facts($project, $expense), actor: $by);
            ActivityLog::record($project, $by, "Requested {$expense->description}, ".Money::format($expense->amount_minor, $project->currency));
            Alerts::spendingRequested($project, $expense, $by);

            return $expense;
        });
    }

    /**
     * Already paid: written down in one step.
     *
     * @param  array{category: CostCategory, description: string, supplier: ?string, amount_minor: int, grant_id: ?string}  $details
     *
     * @throws ValidationException
     */
    public static function record(Project $project, array $details, CarbonImmutable $paidOn, User $by): Expense
    {
        self::requireSpendable($project, $details);

        return DB::transaction(function () use ($project, $details, $paidOn, $by) {
            $expense = $project->expenses()->create([
                ...$details,
                'status' => ExpenseStatus::Paid,
                'direct' => true,
                'spent_on' => $paidOn,
                'recorded_by' => $by->id,
                'paid_by' => $by->id,
            ]);
            app(ProjectBooks::class)->expensePaid($expense, $project);
            Audit::record('expense.recorded', $expense, self::facts($project, $expense), actor: $by);
            ActivityLog::record($project, $by, "Recorded an expense: {$expense->description}, ".Money::format($expense->amount_minor, $project->currency));

            return $expense;
        });
    }

    /**
     * One approval. Spending over a policy band needs more than one
     * approver, each a different person; it is committed (approved) with
     * the last. Budget and grant are checked at every approval.
     *
     * @return bool whether the spending is now approved
     *
     * @throws ValidationException
     */
    public static function approve(Expense $expense, ?string $note, User $by): bool
    {
        return DB::transaction(function () use ($expense, $note, $by) {
            $project = self::lock($expense);
            $expense->refresh();
            self::requireStatus($expense, ExpenseStatus::Requested, 'Only a request awaiting approval is approved.');
            self::requireOtherThanRequester($expense, $by, 'approves');

            $left = self::categoryAvailable($project, $expense->category);
            if ($left === null) {
                throw ValidationException::withMessages(['expense' => 'The project has no approved budget yet.']);
            }
            if ($expense->amount_minor > $left) {
                throw ValidationException::withMessages(['expense' => "{$expense->category->label()}: only ".Money::format(max(0, $left), $project->currency).' of the approved budget is left.']);
            }
            if ($expense->grant_id !== null) {
                $allocation = self::allocation($project, $expense->grant_id);
                if ($allocation === null || $allocation->grant->status !== GrantStatus::Active) {
                    throw ValidationException::withMessages(['expense' => 'The grant charged is not active on this project.']);
                }
                $grantLeft = $allocation->project_amount_minor - self::chargedToGrant($project->id, $expense->grant_id);
                if ($expense->amount_minor > $grantLeft) {
                    throw ValidationException::withMessages(['expense' => 'Only '.Money::format(max(0, $grantLeft), $project->currency)." of {$allocation->grant->reference} is left for this project."]);
                }
            }

            if ($expense->approvals()->where('user_id', $by->id)->exists()) {
                throw ValidationException::withMessages(['expense' => 'You have approved this already: it needs someone else.']);
            }
            $expense->approvals()->create(['user_id' => $by->id, 'note' => $note, 'created_at' => now()]);
            $given = $expense->approvals()->count();
            $facts = [...self::facts($project, $expense), 'approvals' => $given, 'approvals_required' => $expense->approvals_required];

            if ($given < $expense->approvals_required) {
                Audit::record('expense.approval_recorded', $expense, $facts, actor: $by);
                ActivityLog::record($project, $by, "Approved {$expense->description} ({$given} of {$expense->approvals_required})");

                return false;
            }

            $expense->forceFill([
                'status' => ExpenseStatus::Approved,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            Audit::record('expense.approved', $expense, $facts, actor: $by);
            ActivityLog::record($project, $by, "Approved {$expense->description}, ".Money::format($expense->amount_minor, $project->currency));
            Alerts::spendingApproved($project, $expense, $by);

            return true;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function reject(Expense $expense, string $reason, User $by): void
    {
        DB::transaction(function () use ($expense, $reason, $by) {
            $project = self::lock($expense);
            $expense->refresh();
            self::requireStatus($expense, ExpenseStatus::Requested, 'Only a request awaiting approval is rejected.');
            self::requireOtherThanRequester($expense, $by, 'rejects');

            $expense->forceFill([
                'status' => ExpenseStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $reason,
            ])->save();
            Audit::record('expense.rejected', $expense, [...self::facts($project, $expense), 'reason' => $reason], actor: $by);
            ActivityLog::record($project, $by, "Rejected {$expense->description}: {$reason}");
            Alerts::spendingRejected($project, $expense, $reason, $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function pay(Expense $expense, CarbonImmutable $paidOn, string $reference, User $by): void
    {
        DB::transaction(function () use ($expense, $paidOn, $reference, $by) {
            $project = self::lock($expense);
            $expense->refresh();
            self::requireStatus($expense, ExpenseStatus::Approved, 'Only approved spending is paid.');
            self::requireOtherThanRequester($expense, $by, 'pays');
            Procurement::requirePayable($expense);

            $expense->forceFill([
                'status' => ExpenseStatus::Paid,
                'spent_on' => $paidOn,
                'paid_by' => $by->id,
                'payment_reference' => $reference,
            ])->save();
            app(ProjectBooks::class)->expensePaid($expense, $project);
            Audit::record('expense.paid', $expense, [...self::facts($project, $expense), 'reference' => $reference, 'paid_on' => $paidOn->toDateString()], actor: $by);
            ActivityLog::record($project, $by, "Paid {$expense->description}, ".Money::format($expense->amount_minor, $project->currency));
            Alerts::spendingPaid($project, $expense, $by);
        });
    }

    /**
     * Cancels open spending, or voids spending that was paid (a correction:
     * the row stays, marked cancelled, with the reason).
     *
     * @throws ValidationException
     */
    public static function cancel(Expense $expense, ?string $reason, User $by): void
    {
        DB::transaction(function () use ($expense, $reason, $by) {
            $project = self::lock($expense);
            $expense->refresh();
            if (! $expense->status->isOpen() && $expense->status !== ExpenseStatus::Paid) {
                throw ValidationException::withMessages(['expense' => 'It is already closed.']);
            }
            if (PettyCashPayment::query()->where('expense_id', $expense->id)->exists()) {
                throw ValidationException::withMessages(['expense' => 'It was paid from petty cash: void it there.']);
            }
            if (AdvanceRetirementLine::query()->where('expense_id', $expense->id)->exists()) {
                throw ValidationException::withMessages(['expense' => 'It accounts for a staff advance, so it stays.']);
            }
            if ($expense->status === ExpenseStatus::Approved) {
                if ($reason === null) {
                    throw ValidationException::withMessages(['reason' => 'Say why this commitment is released.']);
                }
                if (PurchaseOrder::query()->where('expense_id', $expense->id)->whereHas('receipts')->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Goods have arrived against this order, so the money is still owed.']);
                }
            }
            $voided = $expense->status === ExpenseStatus::Paid;
            if ($voided && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'Say why a payment is voided.']);
            }

            $was = $expense->status;
            $expense->forceFill([
                'status' => ExpenseStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'decision_note' => $reason ?? $expense->decision_note,
            ])->save();
            if ($voided) {
                app(ProjectBooks::class)->expenseVoided($expense, (string) $reason);
            }
            Audit::record($voided ? 'expense.voided' : 'expense.cancelled', $expense, [...self::facts($project, $expense), 'was' => $was->value, 'reason' => $reason], actor: $by);
            ActivityLog::record($project, $by, ($voided ? 'Voided ' : 'Cancelled ').$expense->description);
        });
    }

    public static function attach(Expense $expense, UploadedFile $file, User $by): ExpenseDocument
    {
        if (in_array($expense->status, [ExpenseStatus::Rejected, ExpenseStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['document' => 'It is closed: nothing more is attached.']);
        }

        $path = $file->store("organizations/{$expense->organization_id}/expenses/{$expense->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['document' => 'The file could not be stored.']);
        }

        $document = $expense->documents()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
        Audit::record('expense.document_attached', $expense, ['document' => $document->original_name, 'size' => $document->size], actor: $by);

        return $document;
    }

    /**
     * A document comes off only while the request awaits approval: what was
     * approved or paid on it stays.
     *
     * @throws ValidationException
     */
    public static function detach(ExpenseDocument $document, User $by): void
    {
        $expense = $document->expense;
        if ($expense->status !== ExpenseStatus::Requested) {
            throw ValidationException::withMessages(['document' => 'Documents stay once spending is decided.']);
        }

        $document->delete();
        Storage::disk(self::DISK)->delete($document->path);
        Audit::record('expense.document_removed', $expense, ['document' => $document->original_name], actor: $by);
    }

    /**
     * What is left of the approved budget in a category (it may be
     * negative after direct records); null without an approved budget.
     */
    public static function categoryAvailable(Project $project, CostCategory $category): ?int
    {
        if (Budgets::approved($project) === null) {
            return null;
        }

        $budget = Budgets::approvedByCategory($project)[$category->value] ?? 0;
        $used = (int) Expense::query()->where('project_id', $project->id)
            ->where('category', $category)
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->sum('amount_minor');

        return $budget - $used;
    }

    /**
     * Committed and spent on a project charged to a grant, in the project's
     * currency.
     */
    public static function chargedToGrant(string $projectId, string $grantId): int
    {
        return (int) Expense::query()->where('project_id', $projectId)->where('grant_id', $grantId)
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->sum('amount_minor');
    }

    /**
     * @param  array{category: CostCategory, amount_minor: int, grant_id: ?string}  $details
     *
     * @throws ValidationException
     */
    private static function requireSpendable(Project $project, array $details): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['description' => 'The project is closed.']);
        }
        if ($details['category'] === CostCategory::Labour) {
            throw ValidationException::withMessages(['category' => 'Labour comes from time logged.']);
        }
        if ($details['amount_minor'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'More than zero.']);
        }
        if ($details['grant_id'] !== null && self::allocation($project, $details['grant_id']) === null) {
            throw ValidationException::withMessages(['grant_id' => 'That grant does not fund this project.']);
        }
    }

    private static function allocation(Project $project, string $grantId): ?GrantAllocation
    {
        return GrantAllocation::query()->with('grant')
            ->where('project_id', $project->id)->where('grant_id', $grantId)->first();
    }

    private static function lock(Expense $expense): Project
    {
        return Project::query()->whereKey($expense->project_id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws ValidationException
     */
    private static function requireStatus(Expense $expense, ExpenseStatus $status, string $message): void
    {
        if ($expense->status !== $status) {
            throw ValidationException::withMessages(['expense' => $message]);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function requireOtherThanRequester(Expense $expense, User $by, string $verb): void
    {
        if ($expense->recorded_by === $by->id) {
            throw ValidationException::withMessages(['expense' => "Someone other than whoever requested it {$verb} it."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(Project $project, Expense $expense): array
    {
        return [
            'project' => $project->code,
            'description' => $expense->description,
            'category' => $expense->category->value,
            'amount_minor' => $expense->amount_minor,
            'grant_id' => $expense->grant_id,
        ];
    }
}
