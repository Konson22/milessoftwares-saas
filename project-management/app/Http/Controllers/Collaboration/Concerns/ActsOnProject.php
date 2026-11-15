<?php

namespace App\Http\Controllers\Collaboration\Concerns;

use App\Access\Access;
use App\Access\CurrentAccess;
use App\Collaboration\Acting;
use App\Collaboration\Models\Participant;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reports and requests are served on two sides by the same controllers:
 * projects/{project}/... for the owner, shared-projects/{participant}/...
 * for a participant. The route says which; App\Collaboration\Acting checks
 * the rest on every request.
 */
trait ActsOnProject
{
    use ShowsProject;

    protected function acting(Request $request): Acting
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $access = app(CurrentAccess::class)->get() ?? abort(403);

        $participantId = $request->route('participant');
        if (is_string($participantId)) {
            $participant = Participant::query()->with(['organization', 'owner'])->find($participantId) ?? abort(404);

            return Acting::forParticipant($participant, $user, $access);
        }

        $project = Project::query()->find((string) $request->route('project')) ?? abort(404);

        return Acting::forOwner($project, $user, $access);
    }

    /**
     * A route on the acting side: projects.{name} or shared-projects.{name}.
     *
     * @param  array<string, string>  $parameters
     */
    protected function sideUrl(Acting $acting, string $name, array $parameters = []): string
    {
        return $acting->isOwner()
            ? route("projects.{$name}", ['project' => $acting->project->id, ...$parameters])
            : route("shared-projects.{$name}", ['participant' => $acting->partyId(), ...$parameters]);
    }

    /**
     * What the page needs to draw its frame: the owner's project header, or
     * the participation it is seen through.
     *
     * @return array<string, mixed>
     */
    protected function frame(Request $request, Acting $acting): array
    {
        if ($acting->isOwner()) {
            return ['side' => 'owner', 'project' => $this->projectHeader($request, $acting->project)];
        }

        /** @var Participant $participant */
        $participant = $acting->participant;

        return [
            'side' => 'participant',
            'participation' => [
                'id' => $participant->id,
                'projectCode' => $acting->project->code,
                'projectName' => $acting->project->name,
                'owner' => $participant->owner->name,
                'relationship' => $participant->relationshipLabel(),
                'url' => route('shared-projects.show', ['participant' => $participant->id]),
            ],
        ];
    }

    protected function currentAccess(): Access
    {
        return app(CurrentAccess::class)->get() ?? abort(403);
    }
}
