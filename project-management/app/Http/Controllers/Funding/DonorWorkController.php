<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Beneficiaries;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Partnership;
use App\Support\DonorProjection;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A donor's view across its beneficiaries: their reports, the projects it
 * funds and their tasks. All of it from App\Support\DonorProjection, so only
 * what each grant shares.
 */
class DonorWorkController extends Controller
{
    /** The most tasks one page carries; it says so when there are more. */
    private const TASK_LIMIT = 500;

    /**
     * The grants beneficiaries share with this donor, and their payments.
     * Totals are per currency, each grant counted once: what was awarded,
     * what the beneficiary recorded as received, and what is still scheduled.
     */
    public function grants(): Response
    {
        $today = $this->today();

        $grants = Beneficiaries::across($this->donor(), fn (Partnership $partnership) => array_map(fn (array $grant) => [
            ...$grant,
            'beneficiary' => $partnership->name,
            'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant['id']], false),
        ], DonorProjection::grants((string) $partnership->funder_id)));

        $payments = Beneficiaries::across($this->donor(), fn (Partnership $partnership) => array_map(fn (array $payment) => [
            ...$payment,
            'beneficiary' => $partnership->name,
            'overdue' => $payment['receivedOn'] === null && $payment['dueOn'] < $today,
            'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $payment['grantId']], false),
        ], DonorProjection::payments((string) $partnership->funder_id)));

        $totals = $grants->groupBy('currency')->map(fn ($inCurrency, string $currency) => [
            'currency' => $currency,
            'awardedMinor' => (int) $inCurrency->sum('amountMinor'),
            'receivedMinor' => (int) $inCurrency->sum('receivedMinor'),
            'scheduledMinor' => (int) $payments->where('currency', $currency)->whereNull('receivedOn')->sum('amountMinor'),
        ])->sortKeys()->values();

        return Inertia::render('portal/beneficiaries/grants', [
            'grants' => $grants->sortBy(['beneficiary', 'reference'])->values(),
            'payments' => $payments->sortBy([
                fn (array $a, array $b) => ($a['receivedOn'] !== null) <=> ($b['receivedOn'] !== null),
                fn (array $a, array $b) => $a['dueOn'] <=> $b['dueOn'],
            ])->values(),
            'totals' => $totals,
        ]);
    }

    public function reports(): Response
    {
        $today = $this->today();

        $reports = Beneficiaries::across($this->donor(), fn (Partnership $partnership) => array_map(fn (array $report) => [
            ...$report,
            'beneficiary' => $partnership->name,
            'overdue' => $report['isOwed'] && $report['dueOn'] < $today,
            'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $report['grantId']], false),
        ], DonorProjection::allReports((string) $partnership->funder_id)));

        return Inertia::render('portal/beneficiaries/reports', [
            'reports' => $reports->sortBy('dueOn')->values(),
        ]);
    }

    public function projects(): Response
    {
        $today = $this->today();

        $projects = Beneficiaries::across($this->donor(), fn (Partnership $partnership) => array_map(fn (array $project) => [
            ...$project,
            'beneficiary' => $partnership->name,
            'grants' => array_map(fn (array $grant) => [
                'reference' => $grant['reference'],
                'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant['id']], false),
            ], $project['grants']),
            'late' => $project['progress']['nextMilestone'] !== null && $project['progress']['nextMilestone']['dueOn'] < $today,
        ], DonorProjection::projects((string) $partnership->funder_id, $today)));

        return Inertia::render('portal/beneficiaries/projects', [
            'projects' => $projects->sortBy(['beneficiary', 'code'])->values(),
        ]);
    }

    public function tasks(): Response
    {
        $today = $this->today();

        $tasks = Beneficiaries::across($this->donor(), fn (Partnership $partnership) => array_map(fn (array $task) => [
            ...$task,
            'beneficiary' => $partnership->name,
            'overdue' => $task['status'] !== 'done' && $task['dueOn'] !== null && $task['dueOn'] < $today,
        ], DonorProjection::tasks((string) $partnership->funder_id)));

        // What is still to do comes first, soonest due; undated after dated.
        $ordered = $tasks->sortBy([
            fn (array $a, array $b) => ($a['status'] === 'done') <=> ($b['status'] === 'done'),
            fn (array $a, array $b) => [$a['dueOn'] === null, $a['dueOn']] <=> [$b['dueOn'] === null, $b['dueOn']],
        ])->values();

        return Inertia::render('portal/beneficiaries/tasks', [
            'tasks' => $ordered->take(self::TASK_LIMIT)->values(),
            'total' => $ordered->count(),
        ]);
    }

    private function donor(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->donor()->timezone)->toDateString();
    }
}
