<?php

namespace App\Support;

use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization as PlatformOrganization;
use Miles\PlatformClient\Events\Outbox;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Tatook M&E (mel-app) keeps a read-only copy of each project that its
 * programmes can link to (ADR-0011). Projects sends it mel.project.updated:
 * a snapshot of the project's code, name, status, dates, progress, approved
 * budget, spent and milestones. No tasks, people, suppliers or spending lines.
 *
 * publish() sends the projects whose snapshot changed since it was last sent
 * (projects:publish-to-mel, on a schedule), so nothing in the way projects
 * are kept has to know about M&E. An organization without M&E has its
 * events skipped by the outbox: after a skip, only one project is sent a day,
 * as a probe, until one gets through; then everything that was skipped goes.
 * The very first run sends one project the same way.
 */
final class MelEvents
{
    public const AUDIENCE = 'mel';

    public const PROJECT_UPDATED = 'mel.project.updated';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly CurrentTenant $tenant,
        private readonly CurrentOrganization $organization,
    ) {}

    /** Whether this deployment knows where M&E is (PLATFORM_MEL_EVENTS_URL). */
    public static function configured(): bool
    {
        return filled(config('platform-client.modules.'.self::AUDIENCE));
    }

    /**
     * Sends the current organization's changed project snapshots.
     *
     * @return int how many were sent
     */
    public function publish(): int
    {
        $organization = $this->organization->require();
        $latest = DB::table('platform_outbox')
            ->where('organization_id', $organization->id)
            ->where('audience', self::AUDIENCE)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['status', 'created_at']);
        // Nothing sent yet, or the last was skipped: one project, as a probe.
        $probing = $latest === null || $latest->status === 'skipped';

        if ($latest !== null && $probing && CarbonImmutable::parse((string) $latest->created_at)->greaterThan(now()->subDay())) {
            return 0;
        }

        $projects = Project::query()->with('milestones')->orderBy('code')->get();
        $metrics = ProjectMetrics::forProjects($projects);
        $sent = DB::table('mel_project_snapshots as snapshot')
            ->leftJoin('platform_outbox as outbox', 'outbox.id', '=', 'snapshot.outbox_id')
            ->where('snapshot.organization_id', $organization->id)
            ->get(['snapshot.project_id', 'snapshot.hash', 'outbox.status'])
            ->keyBy('project_id');

        $count = 0;
        foreach ($projects as $project) {
            $snapshot = self::snapshot($project, $metrics[$project->id]['progress'], $metrics[$project->id]['actualMinor']);
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $last = $sent->get($project->id);

            if ($last !== null && $last->hash === $hash && ! in_array($last->status, ['skipped', null], true)) {
                continue;
            }

            $this->send($organization, $project, $snapshot, $hash);
            $count++;

            if ($probing) {
                break;
            }
        }

        return $count;
    }

    /**
     * What M&E is told about a project (contracts/events/mel.project.updated.v1).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Project $project, int $progress, int $spentMinor): array
    {
        return [
            'project_id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status,
            'starts_on' => $project->start_on?->toDateString(),
            'ends_on' => $project->due_on?->toDateString(),
            'progress_percent' => max(0, min(100, $progress)),
            'currency' => $project->currency,
            'approved_budget_minor' => $project->budget_minor,
            'spent_minor' => $spentMinor,
            'milestones' => $project->milestones->map(fn (Milestone $milestone) => [
                'milestone_id' => $milestone->id,
                'name' => $milestone->name,
                'due_on' => $milestone->due_on->toDateString(),
                'reached_on' => $milestone->completed_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function send(Organization $organization, Project $project, array $snapshot, string $hash): void
    {
        DB::transaction(function () use ($organization, $project, $snapshot, $hash) {
            // A new transaction id per send: the same snapshot may be sent
            // again after a skip. M&E keeps the newest by occurred_at.
            $record = fn (): string => $this->outbox->record(self::AUDIENCE, self::PROJECT_UPDATED, 1, "project:{$project->id}:".Str::uuid7(), $snapshot);
            $outboxId = $this->tenant->has() ? $record() : $this->tenant->run($this->platformOrganization($organization), $record);

            DB::table('mel_project_snapshots')->upsert([[
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'hash' => $hash,
                'outbox_id' => $outboxId,
                'sent_at' => now(),
            ]], ['project_id'], ['hash', 'outbox_id', 'sent_at']);
        });
    }

    private function platformOrganization(Organization $organization): PlatformOrganization
    {
        return new PlatformOrganization(
            id: $organization->id,
            slug: (string) $organization->slug,
            displayName: (string) $organization->name,
            status: 'active',
            countryCode: null,
            defaultCurrency: $organization->currency,
            timezone: $organization->timezone,
        );
    }
}
