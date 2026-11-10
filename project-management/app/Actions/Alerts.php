<?php

namespace App\Actions;

use App\Access\AccessResolver;
use App\Models\BudgetVersion;
use App\Models\ChangeRequest;
use App\Models\Expense;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AlertEmail;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Support\Collection;

/**
 * Who hears about approvals, and the writing of any notification that opens a
 * page instead of a task. Told in the app, and by email unless they turned
 * email off. Nobody is told about what they did themselves.
 */
final class Alerts
{
    /**
     * Active members holding a permission, as their access stands now.
     *
     * @return Collection<int, User>
     */
    public static function holders(string $permission): Collection
    {
        $organization = app(CurrentOrganization::class)->require();
        $resolver = new AccessResolver;

        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('status', OrganizationMembership::ACTIVE)
            ->with('user')->get()
            ->filter(fn (OrganizationMembership $membership) => $membership->user->isActive() && $resolver->for($membership)->has($permission))
            ->map(fn (OrganizationMembership $membership) => $membership->user)
            ->values();
    }

    /**
     * @param  iterable<User>  $users
     * @param  string  $path  a path in the organization
     * @param  string|null  $key  gives this once per person (reminders)
     * @param  bool  $email  now; otherwise it waits for the daily digest
     */
    public static function tell(iterable $users, string $kind, string $title, ?string $excerpt, string $path, ?User $by, ?string $key = null, bool $email = true): int
    {
        $organization = app(CurrentOrganization::class)->require();
        $told = 0;

        foreach ($users as $user) {
            if ($user->id === $by?->id) {
                continue;
            }
            if ($key !== null && UserNotification::query()->where('user_id', $user->id)->where('dedupe_key', $key)->exists()) {
                continue;
            }

            $title = mb_substr($title, 0, 255);
            $excerpt = $excerpt === null ? null : mb_substr($excerpt, 0, 200);
            $sendNow = $email && $user->wantsEmailAlerts();
            UserNotification::query()->create([
                'user_id' => $user->id,
                'actor_id' => $by?->id,
                'kind' => $kind,
                'title' => $title,
                'excerpt' => $excerpt,
                'url' => $path,
                'dedupe_key' => $key,
                'emailed_at' => $sendNow ? now() : null,
                'created_at' => now(),
            ]);
            if ($sendNow) {
                $user->notify(new AlertEmail($organization->name, $title, $excerpt, Hosts::organization($organization, $path)));
            }
            $told++;
        }

        return $told;
    }

    public static function spendingRequested(Project $project, Expense $expense, User $by): void
    {
        self::tell(
            self::holders('finance.expenses.approve'),
            UserNotification::APPROVAL,
            "{$by->name} asks you to approve spending on {$project->code}",
            self::about($project, $expense),
            route('approvals', [], false),
            $by,
        );
    }

    /**
     * The requester hears, and those who pay have something to pay.
     */
    public static function spendingApproved(Project $project, Expense $expense, User $by): void
    {
        self::tellRequester($project, $expense, "{$by->name} approved your spending on {$project->code}", $by);
        self::tell(
            self::holders('finance.payments.record')->where('id', '!=', $expense->recorded_by),
            UserNotification::APPROVAL,
            "Approved spending on {$project->code} is ready to pay",
            self::about($project, $expense),
            route('approvals', [], false),
            $by,
        );
    }

    public static function spendingRejected(Project $project, Expense $expense, string $reason, User $by): void
    {
        self::tellRequester($project, $expense, "{$by->name} rejected your spending on {$project->code}: {$reason}", $by);
    }

    public static function spendingPaid(Project $project, Expense $expense, User $by): void
    {
        self::tellRequester($project, $expense, "{$by->name} paid your spending on {$project->code}", $by);
    }

    public static function budgetSubmitted(Project $project, BudgetVersion $version, User $by): void
    {
        self::tell(
            self::holders('finance.budgets.approve'),
            UserNotification::APPROVAL,
            "{$by->name} asks you to approve the budget of {$project->code}",
            "Version {$version->number} of {$project->name}",
            route('approvals', [], false),
            $by,
        );
    }

    public static function budgetDecided(Project $project, BudgetVersion $version, string $title, User $by): void
    {
        $submitter = $version->submitted_by === null ? null : User::query()->find($version->submitted_by);
        if ($submitter !== null) {
            self::tell([$submitter], UserNotification::DECISION, $title, "Version {$version->number} of {$project->name}", route('projects.budget', ['project' => $project->id], false), $by);
        }
    }

    public static function changeRaised(Project $project, ChangeRequest $change, User $by): void
    {
        self::tell(
            self::holders('projects.changes.approve'),
            UserNotification::APPROVAL,
            "{$by->name} asks you to decide {$change->reference($project->code)}",
            $change->title,
            route('projects.changes.index', ['project' => $project->id], false),
            $by,
        );
    }

    public static function changeDecided(Project $project, ChangeRequest $change, string $title, User $by): void
    {
        $raiser = User::query()->find($change->raised_by);
        if ($raiser !== null) {
            self::tell([$raiser], UserNotification::DECISION, $title, $change->title, route('projects.changes.index', ['project' => $project->id], false), $by);
        }
    }

    private static function tellRequester(Project $project, Expense $expense, string $title, User $by): void
    {
        $requester = User::query()->find($expense->recorded_by);
        if ($requester !== null) {
            self::tell([$requester], UserNotification::DECISION, $title, self::about($project, $expense), route('projects.expenses.index', ['project' => $project->id], false), $by);
        }
    }

    private static function about(Project $project, Expense $expense): string
    {
        return "{$expense->description}, ".Money::format($expense->amount_minor, $project->currency);
    }
}
