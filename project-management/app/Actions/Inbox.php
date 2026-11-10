<?php

namespace App\Actions;

use App\Access\AccessResolver;
use App\Models\OrganizationMembership;
use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * Who hears about what happens on a task. Only people who could open the task
 * right now: an active user, an active member of the organization, allowed to
 * view projects. Nobody is told about what they did themselves. Nothing here
 * sends email: what stays unread goes in the daily digest (App\Actions\Reminders).
 */
final class Inbox
{
    /**
     * People who can be told about tasks here, checked against their access
     * as it stands now.
     *
     * @return Collection<int, Person>
     */
    public static function reachable(): Collection
    {
        $people = Person::query()->where('active', true)->whereNotNull('user_id')->with('user')->orderBy('name')->get();
        if ($people->isEmpty()) {
            return collect();
        }

        $organizationId = $people->first()->organization_id;
        $memberships = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('status', OrganizationMembership::ACTIVE)
            ->whereIn('user_id', $people->pluck('user_id'))
            ->get()->keyBy('user_id');
        $resolver = new AccessResolver;

        return collect($people->all())->filter(function (Person $person) use ($memberships, $resolver) {
            $membership = $memberships->get((string) $person->user_id);

            return $person->user?->isActive() === true
                && $membership !== null
                && $resolver->for($membership)->has('projects.view');
        })->values();
    }

    /**
     * The people @mentioned in some text: "@Mary Akech", in any case. The
     * longest name wins, so "@Mary Akech" doesn't also mention a "Mary", and
     * an address like "a@mary.org" mentions nobody.
     *
     * @return Collection<int, Person>
     */
    public static function mentionedIn(string $text): Collection
    {
        if (! str_contains($text, '@')) {
            return collect();
        }

        $mentioned = collect();
        $people = self::reachable()->sortByDesc(fn (Person $person) => mb_strlen($person->name));
        foreach ($people as $person) {
            $pattern = '/(?<![\p{L}\p{N}])@'.preg_quote($person->name, '/').'(?![\p{L}\p{N}])/iu';
            $without = preg_replace($pattern, '', $text);
            if ($without !== null && $without !== $text) {
                $mentioned->push($person);
                $text = $without;
            }
        }

        return $mentioned->values();
    }

    /**
     * Tells these people about the task, those who can be told.
     *
     * @param  iterable<Person>  $people
     * @return list<string> the ids of the people told
     */
    public static function notify(Task $task, string $kind, iterable $people, User $by, ?string $excerpt = null): array
    {
        $ids = collect($people)->map(fn (Person $person) => $person->id)->all();
        if ($ids === []) {
            return [];
        }

        $told = [];
        foreach (self::reachable()->whereIn('id', $ids)->where('user_id', '!=', $by->id) as $person) {
            UserNotification::query()->create([
                'user_id' => $person->user_id,
                'actor_id' => $by->id,
                'kind' => $kind,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'title' => mb_substr(self::title($kind, $by, $task), 0, 255),
                'excerpt' => mb_substr($excerpt ?? $task->title, 0, 200),
                'created_at' => now(),
            ]);
            $told[] = $person->id;
        }

        return $told;
    }

    private static function title(string $kind, User $by, Task $task): string
    {
        return match ($kind) {
            UserNotification::MENTIONED => "{$by->name} mentioned you on {$task->number}",
            UserNotification::COMMENTED => "{$by->name} commented on {$task->number}",
            UserNotification::ASSIGNED => "{$by->name} assigned you {$task->number}",
            default => "{$by->name} added you to {$task->number}",
        };
    }
}
