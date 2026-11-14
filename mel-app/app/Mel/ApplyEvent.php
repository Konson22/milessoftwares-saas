<?php

namespace App\Mel;

use App\Models\LinkedMilestone;
use App\Models\LinkedProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Miles\SharedModuleKit\Envelope;

/**
 * Applies an event from Projects, inside the inbox's transaction (once per
 * business transaction).
 *
 *   mel.project.updated v1   a snapshot of one project: its code, name,
 *                            status, dates, progress, approved budget,
 *                            spent and milestones (contracts/events)
 *
 * The copy in linked_projects is read-only here and as of synced_at, the
 * snapshot's occurred_at. A snapshot older than the copy (delivered late,
 * after a newer one) changes nothing.
 */
class ApplyEvent
{
    public const SOURCE = 'projects';

    /**
     * @throws UnsupportedEvent
     */
    public function handle(Envelope $event): void
    {
        match ("{$event->eventType}.v{$event->schemaVersion}") {
            'mel.project.updated.v1' => $this->project($event),
            default => throw new UnsupportedEvent("M&E does not handle {$event->eventType} v{$event->schemaVersion}."),
        };
    }

    private function project(Envelope $event): void
    {
        if ($event->sourceApp !== self::SOURCE) {
            throw new UnsupportedEvent("Project snapshots come from Projects, not {$event->sourceApp}.");
        }

        $payload = Validator::validate($event->payload, [
            'project_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:24'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'approved_budget_minor' => ['nullable', 'integer'],
            'spent_minor' => ['nullable', 'integer'],
            'milestones' => ['present', 'array', 'max:500'],
            'milestones.*.milestone_id' => ['required', 'uuid', 'distinct'],
            'milestones.*.name' => ['required', 'string', 'max:255'],
            'milestones.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'milestones.*.reached_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $at = CarbonImmutable::instance($event->occurredAt);
        $project = LinkedProject::query()->where('external_id', $payload['project_id'])->lockForUpdate()->first();

        if ($project !== null && $project->synced_at->greaterThan($at)) {
            return;
        }

        $project ??= new LinkedProject(['external_id' => $payload['project_id']]);
        $project->fill([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'status' => $payload['status'],
            'starts_on' => $payload['starts_on'] ?? null,
            'ends_on' => $payload['ends_on'] ?? null,
            'progress_percent' => $payload['progress_percent'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'approved_budget_minor' => $payload['approved_budget_minor'] ?? null,
            'spent_minor' => $payload['spent_minor'] ?? null,
            'synced_at' => $at,
        ])->save();

        $this->milestones($project, $payload['milestones']);
    }

    /**
     * The project's milestones as Projects has them now. One removed there
     * goes here too, unless a result is tied to it: then it stays, as last
     * reported, so the result keeps its context.
     *
     * @param  array<int, array{milestone_id: string, name: string, due_on?: string|null, reached_on?: string|null}>  $milestones
     */
    private function milestones(LinkedProject $project, array $milestones): void
    {
        $kept = [];
        foreach ($milestones as $milestone) {
            $kept[] = $milestone['milestone_id'];
            LinkedMilestone::query()->updateOrCreate(
                ['linked_project_id' => $project->id, 'external_id' => $milestone['milestone_id']],
                [
                    'name' => $milestone['name'],
                    'due_on' => $milestone['due_on'] ?? null,
                    'reached_on' => $milestone['reached_on'] ?? null,
                ],
            );
        }

        LinkedMilestone::query()
            ->where('linked_project_id', $project->id)
            ->whereNotIn('external_id', $kept)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('results')->whereColumn('results.linked_milestone_id', 'linked_milestones.id'))
            ->delete();
    }
}
