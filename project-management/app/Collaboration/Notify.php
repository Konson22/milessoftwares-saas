<?php

namespace App\Collaboration;

use App\Actions\Alerts;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * Tells a party of a project about a report or request, through the
 * existing notifications, in that party's own workspace: the owner's
 * members holding the permission, or a participant's representatives and
 * collaboration managers holding it. Keys stop the same news twice.
 */
final class Notify
{
    /**
     * @param  'reports'|'requests'  $kind
     */
    public static function party(Project $project, ?Participant $party, string $permission, string $kind, string $id, string $title, ?string $excerpt, ?User $by, ?string $key = null, string $type = UserNotification::MESSAGE): int
    {
        $organization = $party === null ? Organization::query()->find($project->organization_id) : $party->organization;
        if ($organization === null || ! $organization->isActive()) {
            return 0;
        }

        return app(CurrentOrganization::class)->run($organization, function () use ($project, $party, $permission, $kind, $id, $title, $excerpt, $by, $key, $type) {
            $path = $party === null
                ? route("projects.{$kind}.show", ['project' => $project->id, $kind === 'reports' ? 'report' : 'projectRequest' => $id], false)
                : route("shared-projects.{$kind}.show", ['participant' => $party->id, $kind === 'reports' ? 'report' : 'projectRequest' => $id], false);

            return Alerts::tell(self::people($party, $permission), $type, $title, $excerpt, $path, $by, $key, email: $type !== UserNotification::REMINDER);
        });
    }

    /**
     * @return Collection<int, User>
     */
    private static function people(?Participant $party, string $permission): Collection
    {
        $holders = Alerts::holders($permission);
        if ($party === null) {
            return $holders;
        }

        $representatives = Representative::query()->where('participant_id', $party->id)->whereNull('removed_at')->pluck('user_id')->all();
        $managers = Alerts::holders('projects.partners.manage')->pluck('id')->all();

        return $holders->filter(fn (User $user) => in_array($user->id, $representatives, true) || in_array($user->id, $managers, true))->values();
    }
}
