<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Budgets;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Support\ProjectMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every commitment across the organization: spending approved and not yet
 * paid. Amounts in different currencies are never added together. This only
 * reports: spending is released or paid on its own project.
 */
class CommitmentController extends Controller
{
    private const AGES = [
        'days30' => 'Up to 30 days',
        'days60' => '31 to 60 days',
        'days90' => '61 to 90 days',
        'over90' => 'Over 90 days',
    ];

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $open = Expense::query()->committed()
            ->with('project:id,code,name,currency', 'grant:id,reference', 'decider:id,name')
            ->orderBy('decided_at')
            ->get();
        $age = fn (Expense $expense) => (int) ($expense->decided_at ?? $expense->created_at)->startOfDay()->diffInDays($today);

        $byCurrency = $open->groupBy(fn (Expense $expense) => $expense->project->currency)->sortKeys()
            ->map(function ($group, string $currency) use ($age) {
                $buckets = array_fill_keys(array_keys(self::AGES), 0);
                foreach ($group as $expense) {
                    $days = $age($expense);
                    $bucket = match (true) {
                        $days <= 30 => 'days30',
                        $days <= 60 => 'days60',
                        $days <= 90 => 'days90',
                        default => 'over90',
                    };
                    $buckets[$bucket] += $expense->amount_minor;
                }

                return [
                    'currency' => $currency,
                    'count' => $group->count(),
                    'totalMinor' => (int) $group->sum('amount_minor'),
                    'buckets' => collect(self::AGES)->map(fn (string $label, string $key) => ['label' => $label, 'amountMinor' => $buckets[$key]])->values()->all(),
                ];
            })->values();

        $projects = Project::query()->whereIn('id', $open->pluck('project_id')->unique())->orderBy('code')->get();
        $metrics = ProjectMetrics::forProjects($projects);
        $committed = $open->groupBy('project_id')->map(fn ($group) => (int) $group->sum('amount_minor'));

        $projectId = $request->string('project')->toString();
        $listed = $projectId === '' ? $open : $open->where('project_id', $projectId)->values();
        $orders = PurchaseOrder::query()->whereIn('expense_id', $listed->pluck('id'))->pluck('number', 'expense_id');

        return Inertia::render('portal/finance/commitments', [
            'byCurrency' => $byCurrency,
            'projects' => $projects->map(function (Project $project) use ($committed, $metrics) {
                $budget = Budgets::approved($project) === null ? null : (int) array_sum(Budgets::approvedByCategory($project));
                $held = $committed[$project->id] ?? 0;
                $spent = $metrics[$project->id]['actualMinor'];

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'currency' => $project->currency,
                    'budgetMinor' => $budget,
                    'committedMinor' => $held,
                    'actualMinor' => $spent,
                    'leftMinor' => $budget === null ? null : $budget - $held - $spent,
                ];
            }),
            'commitments' => $listed->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'projectId' => $expense->project_id,
                'projectCode' => $expense->project->code,
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'supplier' => $expense->supplier,
                'order' => $orders[$expense->id] ?? null,
                'grant' => $expense->grant?->reference,
                'amountMinor' => $expense->amount_minor,
                'currency' => $expense->project->currency,
                'approvedOn' => ($expense->decided_at ?? $expense->created_at)->toDateString(),
                'approvedBy' => $expense->decider?->name,
                'ageDays' => $age($expense),
            ]),
            'projectFilter' => $projectId === '' ? null : $projectId,
        ]);
    }
}
