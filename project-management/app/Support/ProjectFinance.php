<?php

namespace App\Support;

use App\Enums\BudgetVersionStatus;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one source of a project's money figures (docs/finance/metric-definitions.md).
 * Every page that shows a project's budget, commitments or spending reads
 * them here, so a figure means the same wherever it appears:
 *
 *   approvedBudgetMinor  total of the approved budget version; null while
 *                        none is approved.
 *   budgetMinor, budgetBasis
 *                        the budget to plan against: the approved one
 *                        ("approved"), else the version being prepared
 *                        ("draft"), else the figure typed on the project
 *                        ("estimate"); null with basis "none". Only
 *                        "approved" may be shown outside the organization.
 *   committedMinor       expenses approved and not yet paid.
 *   paidMinor            expenses recorded as paid. Money that left.
 *   personnelEstimateMinor
 *                        time logged × hand-typed hourly rates. An estimate
 *                        for internal management: never added to paid, and
 *                        never shown outside the owner's finance viewers.
 *   availableMinor       approved − committed − paid − personnel estimate,
 *                        the sum of what each budget category has left (the
 *                        labour line is used by the estimate, because staff
 *                        cost cannot be booked as an expense). Null without
 *                        an approved budget.
 *
 * Amounts are in the project's currency and never summed across currencies
 * (totalsByCurrency). Each set carries its provenance: where the figures
 * come from and whether Finance has posted the payments.
 *
 * No access checks here: callers check projects.finance.view, a
 * participant's budget.view or a grant's portal switches first.
 *
 * @phpstan-type Figures array{currency: string, approvedBudgetMinor: int|null, budgetMinor: int|null, budgetBasis: string, committedMinor: int, paidMinor: int, personnelEstimateMinor: int, availableMinor: int|null, provenance: Provenance}
 * @phpstan-type Provenance array{currency: string, asOf: string, source: string, paidStatus: string, unpostedPayments: int}
 */
final class ProjectFinance
{
    /** Payments recorded in Projects, not (yet) confirmed posted by Finance. */
    public const RECORDED = 'recorded';

    /** Every payment accepted and posted by Finance. */
    public const POSTED = 'posted';

    /** Outbox outcomes meaning Finance posted the event (201, or 200 already posted). */
    private const POSTED_OUTCOMES = ['delivered', 'duplicate'];

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, Figures>
     */
    public static function forProjects(Collection $projects): array
    {
        $ids = $projects->pluck('id')->values()->all();
        if ($ids === []) {
            return [];
        }

        $versions = BudgetVersion::query()->whereIn('project_id', $ids)
            ->whereIn('status', [BudgetVersionStatus::Approved, BudgetVersionStatus::Draft, BudgetVersionStatus::Submitted])
            ->get(['project_id', 'status', 'total_minor'])
            ->groupBy('project_id');
        $hasVersions = BudgetVersion::query()->whereIn('project_id', $ids)->distinct()->pluck('project_id')->flip();
        $expenses = Expense::query()->whereIn('project_id', $ids)
            ->where(fn ($query) => $query->paid()->orWhere(fn ($query) => $query->committed()))
            ->groupBy('project_id', 'status')
            ->selectRaw('project_id, status, sum(amount_minor) as amount')
            ->get();
        $personnel = DB::table('time_entries')->whereIn('project_id', $ids)
            ->groupBy('project_id')->selectRaw('project_id, sum(cost_minor) as cost')
            ->pluck('cost', 'project_id');
        $unposted = self::unpostedPayments($ids);
        $asOf = now()->toDateString();

        $figures = [];
        foreach ($projects as $project) {
            $id = (string) $project->id;
            $ofProject = $versions->get($id) ?? collect();
            $approved = $ofProject->first(fn (BudgetVersion $version) => $version->status === BudgetVersionStatus::Approved);
            $working = $ofProject->first(fn (BudgetVersion $version) => $version->status !== BudgetVersionStatus::Approved);
            $sum = fn (string $status) => (int) $expenses->filter(fn (Expense $row) => $row->project_id === $id && $row->status->value === $status)
                ->sum(fn (Expense $row) => (int) $row->getAttribute('amount'));
            $paid = $sum('paid');
            $committed = $sum('approved');
            $estimate = (int) ($personnel->get($id) ?? 0);

            [$budget, $basis] = match (true) {
                $approved !== null => [(int) $approved->total_minor, 'approved'],
                $working !== null => [(int) $working->total_minor, 'draft'],
                ! $hasVersions->has($id) && $project->budget_minor > 0 => [(int) $project->budget_minor, 'estimate'],
                default => [null, 'none'],
            };
            $approvedMinor = $approved === null ? null : (int) $approved->total_minor;

            $figures[$id] = [
                'currency' => $project->currency,
                'approvedBudgetMinor' => $approvedMinor,
                'budgetMinor' => $budget,
                'budgetBasis' => $basis,
                'committedMinor' => $committed,
                'paidMinor' => $paid,
                'personnelEstimateMinor' => $estimate,
                'availableMinor' => $approvedMinor === null ? null : $approvedMinor - $committed - $paid - $estimate,
                'provenance' => [
                    'currency' => $project->currency,
                    'asOf' => $asOf,
                    'source' => 'projects',
                    'paidStatus' => $paid > 0 && ($unposted[$id] ?? 0) === 0 ? self::POSTED : self::RECORDED,
                    'unpostedPayments' => $unposted[$id] ?? 0,
                ],
            ];
        }

        return $figures;
    }

    /**
     * @return Figures
     */
    public static function for(Project $project): array
    {
        return self::forProjects(collect([$project]))[(string) $project->id];
    }

    /**
     * Sums per currency, never across. Budgets add up only where approved;
     * projects without one are counted, not guessed.
     *
     * @param  array<string, Figures>  $figures
     * @return list<array{currency: string, projects: int, approvedBudgetMinor: int, withoutApprovedBudget: int, committedMinor: int, paidMinor: int, personnelEstimateMinor: int}>
     */
    public static function totalsByCurrency(array $figures): array
    {
        return array_values(collect($figures)->groupBy('currency')->sortKeys()->map(fn (Collection $group, string $currency) => [
            'currency' => $currency,
            'projects' => $group->count(),
            'approvedBudgetMinor' => (int) $group->sum(fn (array $row) => $row['approvedBudgetMinor'] ?? 0),
            'withoutApprovedBudget' => $group->whereNull('approvedBudgetMinor')->count(),
            'committedMinor' => (int) $group->sum('committedMinor'),
            'paidMinor' => (int) $group->sum('paidMinor'),
            'personnelEstimateMinor' => (int) $group->sum('personnelEstimateMinor'),
        ])->all());
    }

    /**
     * What may leave the organization (participants, funders, M&E): the
     * approved budget, commitments, paid, with provenance. Never the
     * personnel estimate, a draft or a typed estimate.
     *
     * @param  Figures  $figures
     * @return array{currency: string, approvedBudgetMinor: int|null, committedMinor: int, paidMinor: int, provenance: Provenance}
     */
    public static function shareable(array $figures): array
    {
        return [
            'currency' => $figures['currency'],
            'approvedBudgetMinor' => $figures['approvedBudgetMinor'],
            'committedMinor' => $figures['committedMinor'],
            'paidMinor' => $figures['paidMinor'],
            'provenance' => $figures['provenance'],
        ];
    }

    /**
     * Provenance of figures built from the given paid expenses only (a
     * funder's grant share of a project, say), so it says nothing of other
     * payments on the project.
     *
     * @param  Collection<int, Expense>  $paid  paid expenses with id and project_id
     * @return Provenance
     */
    public static function provenanceOf(string $currency, Collection $paid): array
    {
        $unposted = $paid->isEmpty() ? 0 : $paid->count() - DB::table('platform_outbox')->where('audience', FinanceEvents::AUDIENCE)
            ->whereIn('organization_id', $paid->pluck('organization_id')->unique()->values()->all())
            ->whereIn('status', self::POSTED_OUTCOMES)
            ->whereIn('source_transaction_id', $paid->map(fn (Expense $expense) => "expense:{$expense->id}:paid")->all())
            ->count();

        return [
            'currency' => $currency,
            'asOf' => now()->toDateString(),
            'source' => 'projects',
            'paidStatus' => $paid->isNotEmpty() && $unposted === 0 ? self::POSTED : self::RECORDED,
            'unpostedPayments' => $unposted,
        ];
    }

    /**
     * Paid expenses whose payment event Finance has not posted, per project.
     * An organization without Finance has none posted ("skipped"), so its
     * payments stay "recorded": entered in Projects, unverified.
     *
     * @param  array<mixed>  $projectIds
     * @return array<string, int>
     */
    private static function unpostedPayments(array $projectIds): array
    {
        $paid = Expense::query()->paid()->whereIn('project_id', $projectIds)->get(['id', 'project_id', 'organization_id']);
        if ($paid->isEmpty()) {
            return [];
        }

        // Only the owning organization's own outbox speaks for its payments.
        $posted = DB::table('platform_outbox')->where('audience', FinanceEvents::AUDIENCE)
            ->whereIn('organization_id', $paid->pluck('organization_id')->unique()->values()->all())
            ->whereIn('status', self::POSTED_OUTCOMES)
            ->whereIn('source_transaction_id', $paid->map(fn (Expense $expense) => "expense:{$expense->id}:paid")->all())
            ->pluck('source_transaction_id')->flip();

        $unposted = [];
        foreach ($paid as $expense) {
            if (! $posted->has("expense:{$expense->id}:paid")) {
                $unposted[(string) $expense->project_id] = ($unposted[(string) $expense->project_id] ?? 0) + 1;
            }
        }

        return $unposted;
    }
}
