<?php

namespace App\Actions;

use App\Access\AccessResolver;
use App\Enums\GrantStatus;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\OrganizationMembership;
use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AlertDigest;
use App\Support\Modules;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;

/**
 * The daily run, for the organization in hand: what is late or coming up, each
 * told once, then one email per person with what they have not read. Safe to
 * run again: keys stop a reminder repeating and emailed_at a line of the digest.
 */
final class Reminders
{
    public const REPORT_DAYS_AHEAD = 7;

    /** A first run does not email an old backlog. */
    public const DIGEST_DAYS_BACK = 7;

    public const DIGEST_LINES = 20;

    /**
     * @return array{reminders: int, digests: int}
     */
    public static function run(): array
    {
        return [
            'reminders' => self::overdueTasks() + (app(CurrentOrganization::class)->require()->hasModule(Modules::GRANTS) ? self::grantReports() + self::overdueGrantPayments() : 0),
            'digests' => self::digests(),
        ];
    }

    private static function overdueTasks(): int
    {
        $told = 0;
        $reachable = Inbox::reachable()->keyBy('id');
        $tasks = Task::query()->with('project')
            ->whereNull('completed_at')->whereNotNull('assignee_id')
            ->whereDate('due_on', '<', today())->get();

        foreach ($tasks as $task) {
            /** @var Person|null $person */
            $person = $reachable->get((string) $task->assignee_id);
            if ($person === null || $person->user === null || $task->project?->isOpen() === false) {
                continue;
            }

            $due = $task->due_on->toDateString();
            $told += self::taskReminder($task, $person->user, "{$task->number} is overdue", "task-overdue:{$task->id}:{$due}");
        }

        return $told;
    }

    private static function taskReminder(Task $task, User $user, string $title, string $key): int
    {
        if (UserNotification::query()->where('user_id', $user->id)->where('dedupe_key', $key)->exists()) {
            return 0;
        }

        UserNotification::query()->create([
            'user_id' => $user->id,
            'kind' => UserNotification::REMINDER,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'title' => mb_substr($title, 0, 255),
            'excerpt' => mb_substr($task->title, 0, 200),
            'dedupe_key' => $key,
            'created_at' => now(),
        ]);

        return 1;
    }

    private static function grantReports(): int
    {
        $told = 0;
        $managers = Alerts::holders('grants.manage');
        $reports = GrantReport::query()->whereDate('due_on', '<=', today()->addDays(self::REPORT_DAYS_AHEAD))->get()
            ->filter(fn (GrantReport $report) => $report->status->isOutstanding());

        foreach ($reports as $report) {
            $grant = Grant::query()->find($report->grant_id);
            if ($grant === null || $grant->status !== GrantStatus::Active) {
                continue;
            }

            $overdue = $report->isOverdue();
            $due = $report->due_on->toDateString();
            $told += Alerts::tell(
                $managers,
                UserNotification::REMINDER,
                $overdue ? "A report to the funder of {$grant->reference} is overdue" : "A report to the funder of {$grant->reference} is due on {$report->due_on->format('j M Y')}",
                $report->title,
                route('grants.show', ['grant' => $grant->id], false),
                null,
                ($overdue ? 'report-overdue' : 'report-due').":{$report->id}:{$due}",
                email: false,
            );
        }

        return $told;
    }

    private static function overdueGrantPayments(): int
    {
        $told = 0;
        $managers = Alerts::holders('grants.manage');
        $payments = GrantDisbursement::query()->whereNull('received_on')->whereDate('due_on', '<', today())->get();

        foreach ($payments as $payment) {
            $grant = Grant::query()->find($payment->grant_id);
            if ($grant === null || $grant->status !== GrantStatus::Active) {
                continue;
            }

            $due = $payment->due_on->toDateString();
            $told += Alerts::tell(
                $managers,
                UserNotification::REMINDER,
                "A payment from the funder of {$grant->reference} is overdue",
                "It was due on {$payment->due_on->format('j M Y')}",
                route('grants.show', ['grant' => $grant->id], false),
                null,
                "payment-overdue:{$payment->id}:{$due}",
                email: false,
            );
        }

        return $told;
    }

    private static function digests(): int
    {
        $organization = app(CurrentOrganization::class)->require();
        $resolver = new AccessResolver;
        $sent = 0;

        $waiting = UserNotification::query()
            ->whereNull('read_at')->whereNull('emailed_at')
            ->where('created_at', '>=', now()->subDays(self::DIGEST_DAYS_BACK))
            ->latest('created_at')->orderByDesc('id')->get()
            ->groupBy('user_id');

        foreach ($waiting as $userId => $notifications) {
            $user = User::query()->find($userId);
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->id)->where('user_id', $userId)
                ->where('status', OrganizationMembership::ACTIVE)->first();
            if ($user === null || $membership === null || ! $user->wantsEmailAlerts()) {
                continue;
            }

            // Task titles only reach someone who can still open tasks.
            $mayOpenTasks = $resolver->for($membership)->has('projects.view');
            $shown = $notifications->filter(fn (UserNotification $notification) => $notification->task_id === null || $mayOpenTasks)->values();
            if ($shown->isEmpty()) {
                continue;
            }

            $user->notify(new AlertDigest(
                $organization->name,
                $shown->count(),
                array_values($shown->take(self::DIGEST_LINES)->map(fn (UserNotification $notification) => $notification->title)->all()),
                Hosts::organization($organization, route('notifications.index', [], false)),
            ));
            UserNotification::query()->whereIn('id', $shown->pluck('id'))->update(['emailed_at' => now()]);
            $sent++;
        }

        return $sent;
    }
}
