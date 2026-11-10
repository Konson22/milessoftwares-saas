<?php

namespace App\Support;

use App\Actions\Budgets;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantAmendment;
use App\Models\GrantDisbursement;
use App\Models\GrantMessage;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything a funder's contact or a connected donor can read, and nothing else: reviewed queries
 * returning named fields, never whole records. What is left out on purpose:
 * internal notes on reports and payments, other funders' grants and
 * allocations, approvals, who requested or decided spending, suppliers,
 * receipts, staff, costs of people, risks, grants still in the pipeline, and
 * of a task, all but its number, title, status and dates. Each part is shared
 * only when the grant says so.
 */
final class DonorProjection
{
    /** The funder never sees a grant being pursued or one it declined. */
    private const VISIBLE = [GrantStatus::Active, GrantStatus::Closed];

    /** A report the funder has been given; one still owed may be a draft. */
    private const DELIVERED = [GrantReportStatus::Submitted, GrantReportStatus::Accepted, GrantReportStatus::RevisionRequested];

    public static function canSee(string $funderId, Grant $grant): bool
    {
        return $grant->funder_id === $funderId
            && $grant->portal_enabled
            && in_array($grant->status, self::VISIBLE, true);
    }

    public static function documentIsDelivered(GrantReport $report): bool
    {
        return in_array($report->status, self::DELIVERED, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function grants(string $funderId): array
    {
        return array_values(self::shared($funderId)->map(fn (Grant $grant) => [
            'id' => $grant->id,
            'reference' => $grant->reference,
            'title' => $grant->title,
            'status' => $grant->status->value,
            'currency' => $grant->currency,
            'amountMinor' => $grant->amount_minor,
            'receivedMinor' => $grant->receivedMinor(),
            'startOn' => $grant->start_on?->toDateString(),
            'endOn' => $grant->end_on?->toDateString(),
            'reportsOwed' => $grant->reports()->get()->filter(fn (GrantReport $report) => $report->status->isOutstanding())->count(),
        ])->all());
    }

    /**
     * Every scheduled payment of every shared grant, received or not. Due
     * dates and amounts only: never the internal notes on a payment.
     *
     * @return list<array{grantId: string, grant: string, currency: string, dueOn: string, amountMinor: int, receivedOn: string|null}>
     */
    public static function payments(string $funderId): array
    {
        return array_values(self::shared($funderId)->flatMap(fn (Grant $grant) => $grant->disbursements()->get()
            ->map(fn (GrantDisbursement $payment) => [
                'grantId' => $grant->id,
                'grant' => $grant->reference,
                'currency' => $grant->currency,
                'dueOn' => $payment->due_on->toDateString(),
                'amountMinor' => $payment->amount_minor,
                'receivedOn' => $payment->received_on?->toDateString(),
            ])->all())->all());
    }

    /**
     * What a donor's dashboard needs of each shared grant: what waits on a
     * report (submitted, or still owed) and payments not received yet.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(string $funderId): array
    {
        return array_values(self::shared($funderId)->map(fn (Grant $grant) => [
            'id' => $grant->id,
            'reference' => $grant->reference,
            'title' => $grant->title,
            'status' => $grant->status->value,
            'currency' => $grant->currency,
            'amountMinor' => $grant->amount_minor,
            'receivedMinor' => $grant->receivedMinor(),
            'reports' => array_values($grant->reports()->get()
                ->filter(fn (GrantReport $report) => $report->status === GrantReportStatus::Submitted || $report->status->isOutstanding())
                ->map(fn (GrantReport $report) => [
                    'id' => $report->id,
                    'title' => $report->title,
                    'dueOn' => $report->due_on->toDateString(),
                    'status' => $report->status->value,
                    'statusLabel' => $report->status->label(),
                    'submittedOn' => $report->submitted_on?->toDateString(),
                ])->all()),
            'payments' => array_values($grant->disbursements()->whereNull('received_on')->get()
                ->map(fn (GrantDisbursement $payment) => [
                    'id' => $payment->id,
                    'dueOn' => $payment->due_on->toDateString(),
                    'amountMinor' => $payment->amount_minor,
                ])->all()),
        ])->all());
    }

    /**
     * Every report of every shared grant, with the grant it belongs to.
     *
     * @return list<array<string, mixed>>
     */
    public static function allReports(string $funderId): array
    {
        return array_values(self::shared($funderId)->flatMap(fn (Grant $grant) => array_map(fn (array $report) => [
            ...$report,
            'grantId' => $grant->id,
            'grant' => $grant->reference,
            'isOwed' => $report['status'] === GrantReportStatus::Due->value || $report['status'] === GrantReportStatus::RevisionRequested->value,
        ], self::reports($grant)))->all());
    }

    /**
     * The projects shared grants fund, each once. A project funded by two
     * grants shows what either shares; every project is named, as on its grant.
     *
     * @return list<array{id: string, code: string, name: string, grants: list<array{id: string, reference: string}>, progress: array{status: string|null, percent: int|null, nextMilestone: array{name: string, dueOn: string}|null, milestonesReached: int, milestones: int}, tasks: array{open: int, done: int, overdue: int}|null}>
     */
    public static function projects(string $funderId, string $today): array
    {
        $projects = [];
        foreach (self::shared($funderId) as $grant) {
            foreach ($grant->allocations()->with('project')->get() as $allocation) {
                $project = $allocation->project;
                $entry = $projects[$project->id] ?? ['project' => $project, 'grants' => [], 'progress' => false, 'milestones' => false, 'tasks' => false];
                $entry['grants'][] = ['id' => $grant->id, 'reference' => $grant->reference];
                $entry['progress'] = $entry['progress'] || $grant->portal_progress;
                $entry['milestones'] = $entry['milestones'] || $grant->portal_milestones;
                $entry['tasks'] = $entry['tasks'] || $grant->portal_tasks;
                $projects[$project->id] = $entry;
            }
        }

        return array_values(array_map(function (array $entry) use ($today) {
            /** @var Project $project */
            $project = $entry['project'];

            return [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'grants' => $entry['grants'],
                'progress' => self::projectStanding($project, $entry['progress'], $entry['milestones']),
                'tasks' => $entry['tasks'] ? self::taskCounts($project, $today) : null,
            ];
        }, $projects));
    }

    /**
     * The tasks of projects whose grant shares them. Top-level ones: a parent
     * stands for its subtasks, as it does in the project's own progress.
     *
     * @return list<array{id: string, number: string, title: string, status: string, dueOn: string|null, completedOn: string|null, project: string}>
     */
    public static function tasks(string $funderId): array
    {
        $projectIds = [];
        foreach (self::shared($funderId)->where('portal_tasks', true) as $grant) {
            foreach ($grant->allocations()->pluck('project_id') as $projectId) {
                $projectIds[(string) $projectId] = true;
            }
        }
        if ($projectIds === []) {
            return [];
        }

        return array_values(Task::query()->with('project:id,code,name')
            ->whereIn('project_id', array_keys($projectIds))->whereNull('parent_id')
            ->get(['id', 'project_id', 'number', 'title', 'status', 'due_on', 'completed_at'])
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'number' => $task->number,
                'title' => $task->title,
                'status' => $task->status,
                'dueOn' => $task->due_on?->toDateString(),
                'completedOn' => $task->completed_at?->toDateString(),
                'project' => "{$task->project->code} {$task->project->name}",
            ])->all());
    }

    /**
     * @return array{status: string|null, percent: int|null, nextMilestone: array{name: string, dueOn: string}|null, milestonesReached: int, milestones: int}
     */
    private static function projectStanding(Project $project, bool $progressShared, bool $milestonesShared): array
    {
        $progress = $progressShared ? self::progress($project) : null;
        $milestones = $milestonesShared ? self::milestones($project) : [];
        $reached = array_filter($milestones, fn (array $milestone) => $milestone['reached']);
        $next = array_values(array_filter($milestones, fn (array $milestone) => ! $milestone['reached']))[0] ?? null;

        return [
            'status' => $progress['status'] ?? null,
            'percent' => $progress['percent'] ?? null,
            'nextMilestone' => $next === null ? null : ['name' => $next['name'], 'dueOn' => $next['dueOn']],
            'milestonesReached' => count($reached),
            'milestones' => count($milestones),
        ];
    }

    /**
     * @return array{open: int, done: int, overdue: int}
     */
    private static function taskCounts(Project $project, string $today): array
    {
        $tasks = Task::query()->where('project_id', $project->id)->whereNull('parent_id')->get(['status', 'due_on']);
        $open = $tasks->filter(fn (Task $task) => $task->status !== Task::DONE);

        return [
            'open' => $open->count(),
            'done' => $tasks->count() - $open->count(),
            'overdue' => $open->filter(fn (Task $task) => $task->due_on !== null && $task->due_on->toDateString() < $today)->count(),
        ];
    }

    /**
     * @return Collection<int, Grant>
     */
    private static function shared(string $funderId): Collection
    {
        return Grant::query()
            ->where('funder_id', $funderId)->where('portal_enabled', true)
            ->whereIn('status', array_map(fn (GrantStatus $status) => $status->value, self::VISIBLE))
            ->orderBy('reference')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function grant(Grant $grant): array
    {
        $allocations = $grant->allocations()->with('project')->get()->sortBy('project.code')->values();

        return [
            'grant' => [
                'id' => $grant->id,
                'reference' => $grant->reference,
                'title' => $grant->title,
                'status' => $grant->status->value,
                'currency' => $grant->currency,
                'amountMinor' => $grant->amount_minor,
                'receivedMinor' => $grant->receivedMinor(),
                'restricted' => $grant->restricted,
                'startOn' => $grant->start_on?->toDateString(),
                'endOn' => $grant->end_on?->toDateString(),
            ],
            'shares' => [
                'budget' => $grant->portal_budget,
                'spending' => $grant->portal_spending,
                'commitments' => $grant->portal_commitments,
                'progress' => $grant->portal_progress,
                'milestones' => $grant->portal_milestones,
                'tasks' => $grant->portal_tasks,
            ],
            'amendments' => array_values($grant->amendments()->get()->map(fn (GrantAmendment $amendment) => [
                'number' => $amendment->number,
                'effectiveOn' => $amendment->effective_on->toDateString(),
                'amountAfterMinor' => $amendment->amount_after_minor,
                'endAfter' => $amendment->end_after?->toDateString(),
            ])->all()),
            'payments' => array_values($grant->disbursements()->get()->map(fn (GrantDisbursement $payment) => [
                'id' => $payment->id,
                'dueOn' => $payment->due_on->toDateString(),
                'amountMinor' => $payment->amount_minor,
                'receivedOn' => $payment->received_on?->toDateString(),
            ])->all()),
            'reports' => self::reports($grant),
            'projects' => array_values($allocations->map(fn (GrantAllocation $allocation) => self::project($grant, $allocation))->all()),
            'messages' => self::messages($grant),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function reports(Grant $grant): array
    {
        return array_values($grant->reports()->with('documents')->get()->map(fn (GrantReport $report) => [
            'id' => $report->id,
            'title' => $report->title,
            'kind' => GrantReport::KINDS[$report->kind] ?? $report->kind,
            'dueOn' => $report->due_on->toDateString(),
            'status' => $report->status->value,
            'statusLabel' => $report->status->label(),
            'submittedOn' => $report->submitted_on?->toDateString(),
            'acceptedOn' => $report->accepted_on?->toDateString(),
            'canDecide' => $report->status === GrantReportStatus::Submitted,
            'documents' => ! self::documentIsDelivered($report) ? [] : array_values($report->documents->map(fn (GrantReportDocument $document) => [
                'id' => $document->id,
                'name' => $document->original_name,
                'size' => $document->size,
            ])->all()),
        ])->all());
    }

    /**
     * One funded project: what the grant gives it, then what the grant shares.
     *
     * @return array<string, mixed>
     */
    private static function project(Grant $grant, GrantAllocation $allocation): array
    {
        $project = $allocation->project;

        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'currency' => $project->currency,
            'allocatedMinor' => $allocation->project_amount_minor,
            'progress' => $grant->portal_progress ? self::progress($project) : null,
            'milestones' => $grant->portal_milestones ? self::milestones($project) : null,
            'tasks' => $grant->portal_tasks ? self::projectTasks($project) : null,
            'budget' => $grant->portal_budget ? self::budget($project) : null,
            'spending' => $grant->portal_spending ? self::spending($grant, $project) : null,
            'commitments' => $grant->portal_commitments ? self::commitments($grant, $project) : null,
        ];
    }

    /**
     * @return list<array{number: string, title: string, status: string, dueOn: string|null}>
     */
    private static function projectTasks(Project $project): array
    {
        return array_values($project->tasks()->whereNull('parent_id')->orderBy('due_on')->orderBy('number')
            ->get(['number', 'title', 'status', 'due_on'])
            ->map(fn (Task $task) => [
                'number' => $task->number,
                'title' => $task->title,
                'status' => $task->status,
                'dueOn' => $task->due_on?->toDateString(),
            ])->all());
    }

    /**
     * @return array{status: string, percent: int}
     */
    private static function progress(Project $project): array
    {
        return [
            'status' => $project->status,
            'percent' => ProjectMetrics::for($project)['progress'],
        ];
    }

    /**
     * @return list<array{name: string, dueOn: string, reached: bool}>
     */
    private static function milestones(Project $project): array
    {
        return array_values($project->milestones()->orderBy('due_on')->get()->map(fn (Milestone $milestone) => [
            'name' => $milestone->name,
            'dueOn' => $milestone->due_on->toDateString(),
            'reached' => $milestone->completed_at !== null,
        ])->all());
    }

    /**
     * The approved budget by category. Nothing until one is approved: a draft
     * is the organization's own.
     *
     * @return array{categories: list<array{category: string, amountMinor: int}>}|null
     */
    private static function budget(Project $project): ?array
    {
        if (Budgets::approved($project) === null) {
            return null;
        }

        $categories = [];
        foreach (Budgets::approvedByCategory($project) as $category => $amount) {
            $categories[] = ['category' => CostCategory::from($category)->label(), 'amountMinor' => (int) $amount];
        }

        return ['categories' => $categories];
    }

    /**
     * Approved but not yet paid on this grant: the total, by category. Not who
     * asked or approved, the supplier, or what each one is.
     *
     * @return array{committedMinor: int, categories: list<array{category: string, amountMinor: int}>}
     */
    private static function commitments(Grant $grant, Project $project): array
    {
        $approved = Expense::query()->where('project_id', $project->id)->where('grant_id', $grant->id)
            ->where('status', ExpenseStatus::Approved)->get(['category', 'amount_minor']);

        return [
            'committedMinor' => (int) $approved->sum('amount_minor'),
            'categories' => array_values($approved->groupBy(fn (Expense $expense) => $expense->category->value)
                ->map(fn ($expenses, string $category) => ['category' => CostCategory::from($category)->label(), 'amountMinor' => (int) $expenses->sum('amount_minor')])
                ->sortBy('category')->values()->all()),
        ];
    }

    /**
     * What was paid from this grant only, line by line. Not who asked or
     * approved, the supplier, or the receipts.
     *
     * @return array{paidMinor: int, lines: list<array{paidOn: string|null, category: string, description: string, amountMinor: int}>}
     */
    private static function spending(Grant $grant, Project $project): array
    {
        $paid = Expense::query()->where('project_id', $project->id)->where('grant_id', $grant->id)
            ->where('status', ExpenseStatus::Paid)->orderByDesc('spent_on')->get();

        return [
            'paidMinor' => (int) $paid->sum('amount_minor'),
            'lines' => array_values($paid->map(fn (Expense $expense) => [
                'paidOn' => $expense->spent_on?->toDateString(),
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'amountMinor' => $expense->amount_minor,
            ])->all()),
        ];
    }

    /**
     * @return list<array{id: string, author: string, fromFunder: bool, body: string, at: string}>
     */
    public static function messages(Grant $grant): array
    {
        $messages = GrantMessage::query()->where('grant_id', $grant->id)->with('author:id,name')
            ->latest('created_at')->orderByDesc('id')->limit(100)->get()->reverse()->values();

        return array_values($messages->map(fn (GrantMessage $message) => [
            'id' => $message->id,
            'author' => $message->author->name,
            'fromFunder' => $message->from_funder,
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ])->all());
    }
}
