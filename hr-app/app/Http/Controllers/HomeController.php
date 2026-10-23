<?php

namespace App\Http\Controllers;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Models\Document;
use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff at a glance, for the staff the member can see: headcount,
 * who joined and left this month, contracts and probations ending soon,
 * documents (permits, licences) that have expired or are about to, and
 * who is on leave today and how many requests wait for a decision.
 */
class HomeController extends Controller
{
    public const SOON_DAYS = 30;

    public function __invoke(StaffScope $scope): Response
    {
        if (! $scope->holds('hr.employees.view')) {
            return Inertia::render('home', ['summary' => null]);
        }

        $today = CarbonImmutable::today();
        $soon = $today->addDays(self::SOON_DAYS);
        $visible = fn () => $scope->apply(Employee::query(), 'hr.employees.view');
        $active = fn () => $visible()->where('status', Employee::ACTIVE);

        $ending = Contract::query()
            ->where('status', Contract::CURRENT)
            ->whereIn('employee_id', $active()->select('id'))
            ->where(fn ($query) => $query
                ->whereBetween('ends_on', [$today->toDateString(), $soon->toDateString()])
                ->orWhereBetween('probation_ends_on', [$today->toDateString(), $soon->toDateString()]))
            ->with('employee')
            ->limit(200)
            ->get()
            ->map(function (Contract $contract) use ($today, $soon) {
                $probation = $contract->probation_ends_on !== null && $contract->probation_ends_on->betweenIncluded($today, $soon);

                return [
                    'employeeId' => $contract->employee_id,
                    'name' => $contract->employee->displayName(),
                    'what' => $probation ? 'Probation ends' : 'Contract ends',
                    'on' => ($probation ? $contract->probation_ends_on : $contract->ends_on)?->toDateString(),
                ];
            })
            ->sortBy('on')
            ->take(20)
            ->values();

        return Inertia::render('home', [
            'summary' => [
                'headcount' => $active()->count(),
                'joinedThisMonth' => $visible()->where('hired_on', '>=', $today->startOfMonth()->toDateString())->count(),
                'leftThisMonth' => $visible()->where('status', Employee::LEFT)->where('left_on', '>=', $today->startOfMonth()->toDateString())->count(),
                'soonDays' => self::SOON_DAYS,
                'endingSoon' => $ending,
                'documents' => $scope->holds('hr.documents.view') ? $this->expiringDocuments($scope, $today) : null,
                'leave' => $scope->holds('hr.leave.view') ? [
                    'awayToday' => LeaveRequest::query()
                        ->whereIn('employee_id', $scope->apply(Employee::query(), 'hr.leave.view')->where('status', Employee::ACTIVE)->select('id'))
                        ->where('status', LeaveStatus::Approved->value)
                        ->whereDate('starts_on', '<=', $today->toDateString())
                        ->whereDate('ends_on', '>=', $today->toDateString())
                        ->count(),
                    'waiting' => LeaveRequest::query()
                        ->whereIn('employee_id', $scope->apply(Employee::query(), 'hr.leave.view')->select('id'))
                        ->where('status', LeaveStatus::Pending->value)
                        ->count(),
                ] : null,
            ],
        ]);
    }

    /**
     * Documents of current staff that have expired or expire within
     * DocumentController::EXPIRING_DAYS, for the staff whose documents the
     * member can see (identity documents only with the personal permission).
     *
     * @return list<array{id: string, employeeId: string, name: string, title: string, category: string, expiresOn: string, expiry: string}>
     */
    private function expiringDocuments(StaffScope $scope, CarbonImmutable $today): array
    {
        $staff = $scope->apply(Employee::query(), 'hr.documents.view')->where('status', Employee::ACTIVE)->select('id');

        return array_values(Document::query()
            ->whereNull('removed_at')
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', $today->addDays(DocumentController::EXPIRING_DAYS)->toDateString())
            ->whereIn('employee_id', $staff)
            ->when(! $scope->organizationWide('hr.personal.view'), fn ($query) => $query->whereNotIn('category', array_map(
                fn (DocumentCategory $category) => $category->value,
                array_filter(DocumentCategory::cases(), fn (DocumentCategory $category) => $category->restricted()),
            )))
            ->with('employee')
            ->orderBy('expires_on')
            ->limit(20)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'employeeId' => $document->employee_id,
                'name' => $document->employee->displayName(),
                'title' => $document->title,
                'category' => $document->category->label(),
                'expiresOn' => (string) $document->expires_on?->toDateString(),
                'expiry' => (string) DocumentController::expiry($document, $today),
            ])
            ->all());
    }
}
