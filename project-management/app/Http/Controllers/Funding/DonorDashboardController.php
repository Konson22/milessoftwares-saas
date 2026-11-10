<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Beneficiaries;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Http\Controllers\Controller;
use App\Models\Partnership;
use App\Support\DonorProjection;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /funding: what a donor funds and what waits for it, across every
 * beneficiary. Only what App\Support\DonorProjection gives, so only what each
 * grant shares. Money is totalled per currency, never across them.
 */
class DonorDashboardController extends Controller
{
    private const LIST_LENGTH = 8;

    public function __invoke(Request $request): Response
    {
        $donor = app(CurrentOrganization::class)->require();

        // Any member lands here; the figures are for those who may see grants.
        if (! $this->can($request, 'grants.view')) {
            return Inertia::render('portal/beneficiaries/dashboard', ['overview' => null]);
        }

        $partnerships = Partnership::query()->with('beneficiary')
            ->where('donor_organization_id', $donor->id)->orderBy('name')->get();
        $connected = $partnerships->filter(fn (Partnership $partnership) => $partnership->isAccepted() && $partnership->beneficiary?->isActive());
        $today = CarbonImmutable::now($donor->timezone)->toDateString();

        $grants = $connected->toBase()->flatMap(function (Partnership $partnership) {
            $beneficiary = $partnership->beneficiary;
            if ($beneficiary === null) {
                return [];
            }

            return collect(app(CurrentOrganization::class)->run($beneficiary, fn () => DonorProjection::overview((string) $partnership->funder_id)))
                ->map(fn (array $grant) => [
                    ...$grant,
                    'beneficiary' => $partnership->name,
                    'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant['id']], false),
                ]);
        })->values();
        $active = $grants->filter(fn (array $grant) => $grant['status'] === GrantStatus::Active->value)->values();

        $reports = $grants->flatMap(fn (array $grant) => array_map(fn (array $report) => [
            ...$report,
            'key' => $report['id'],
            'grant' => $grant['reference'],
            'beneficiary' => $grant['beneficiary'],
            'url' => $grant['url'],
            'overdue' => $report['dueOn'] < $today,
        ], $grant['reports']));
        $toDecide = $reports->filter(fn (array $report) => $report['status'] === GrantReportStatus::Submitted->value)->sortBy('submittedOn')->values();
        $owed = $reports->filter(fn (array $report) => $report['status'] !== GrantReportStatus::Submitted->value)->sortBy('dueOn')->values();

        $payments = $active->flatMap(fn (array $grant) => array_map(fn (array $payment) => [
            ...$payment,
            'grant' => $grant['reference'],
            'beneficiary' => $grant['beneficiary'],
            'currency' => $grant['currency'],
            'url' => $grant['url'],
            'overdue' => $payment['dueOn'] < $today,
        ], $grant['payments']))->sortBy('dueOn')->values();

        $projects = Beneficiaries::across($donor, fn (Partnership $partnership) => array_map(fn (array $project) => [
            'id' => $project['id'],
            'code' => $project['code'],
            'name' => $project['name'],
            'beneficiary' => $partnership->name,
            'status' => $project['progress']['status'],
            'percent' => $project['progress']['percent'],
            'tasksShared' => $project['tasks'] !== null,
            'openTasks' => $project['tasks']['open'] ?? 0,
            'overdueTasks' => $project['tasks']['overdue'] ?? 0,
        ], DonorProjection::projects((string) $partnership->funder_id, $today)))->sortBy(['beneficiary', 'code'])->values();

        return Inertia::render('portal/beneficiaries/dashboard', [
            'overview' => [
                'projectCount' => $projects->count(),
                'tasksShared' => $projects->contains('tasksShared', true),
                'openTasks' => (int) $projects->sum('openTasks'),
                'overdueTasks' => (int) $projects->sum('overdueTasks'),
                'projects' => $projects->take(self::LIST_LENGTH)->values(),
                'connected' => $connected->count(),
                'invited' => $partnerships->filter(fn (Partnership $partnership) => ! $partnership->isAccepted() && ! $partnership->expires_at->isPast())->count(),
                'activeGrants' => $active->count(),
                'toDecideCount' => $toDecide->count(),
                'owedCount' => $owed->count(),
                'paymentsCount' => $payments->count(),
                'funding' => $this->fundingByCurrency($active),
                'grants' => $active->map(fn (array $grant) => [
                    'id' => $grant['id'],
                    'reference' => $grant['reference'],
                    'title' => $grant['title'],
                    'beneficiary' => $grant['beneficiary'],
                    'currency' => $grant['currency'],
                    'amountMinor' => $grant['amountMinor'],
                    'receivedMinor' => $grant['receivedMinor'],
                    'url' => $grant['url'],
                ])->sortBy(['beneficiary', 'reference'])->values(),
                'toDecide' => $toDecide->take(self::LIST_LENGTH)->values(),
                'owed' => $owed->take(self::LIST_LENGTH)->values(),
                'payments' => $payments->take(self::LIST_LENGTH)->values(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $grants
     * @return list<array{currency: string, amountMinor: int, receivedMinor: int}>
     */
    private function fundingByCurrency(Collection $grants): array
    {
        return array_values($grants->groupBy('currency')->map(fn (Collection $group, string $currency) => [
            'currency' => $currency,
            'amountMinor' => (int) $group->sum('amountMinor'),
            'receivedMinor' => (int) $group->sum('receivedMinor'),
        ])->sortBy('currency')->all());
    }
}
