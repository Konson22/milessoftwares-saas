<?php

namespace App\Mel;

use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Integration\IntegrationClient;
use Miles\PlatformClient\Integration\IntegrationResult;
use Miles\PlatformClient\Integration\IntegrationStatus;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The link to Tatook Projects (ADR-0019 §9, §20). Projects stays the record
 * of projects and milestones; M&E keeps only their ids on units and results
 * (`linked_project_id`, `linked_milestone_id`, no foreign key across
 * applications) and reads the rest live from Projects' integration API, as
 * the signed-in member: Projects answers with what that member may see
 * there (projects.view, team-only projects, money only with
 * projects.finance.view). Nothing is copied or cached.
 *
 * On when this deployment knows where Projects is
 * (PLATFORM_PROJECTS_INTEGRATION_URL). Every M&E workflow works without it,
 * and when Projects cannot be read, M&E says so: a link is never dropped or
 * shown as "no project" because a read failed.
 */
final class LinkedProjects
{
    public const PAIR = 'projects';

    /** Not configured, or no member signed in: nothing is read. */
    public const OFF = 'off';

    public static function enabled(): bool
    {
        return app(IntegrationClient::class)->configured(self::PAIR);
    }

    /**
     * Figures for each linked project the member may see, by project id.
     *
     * @param  Collection<int, ProgrammeUnit>  $units
     * @return array<string, array<string, mixed>>
     */
    public static function figures(Collection $units, Access $access): array
    {
        return self::read($units, $access)['projects'];
    }

    /**
     * The linked projects as Projects answers for this member, how the read
     * went, and a sentence for the page when it did not.
     *
     * @param  Collection<int, ProgrammeUnit>  $units
     * @return array{status: string, message: string|null, projects: array<string, array<string, mixed>>}
     */
    public static function read(Collection $units, Access $access, bool $withMilestones = false): array
    {
        $ids = $units->pluck('linked_project_id')->filter()->map(fn (mixed $id) => (string) $id)->unique()->values()->all();
        if ($ids === []) {
            return ['status' => IntegrationStatus::Ok->value, 'message' => null, 'projects' => []];
        }

        $result = self::get($access, 'projects', ['ids' => $ids] + ($withMilestones ? ['with' => 'milestones'] : []));
        if ($result === null || ! $result->ok()) {
            return ['status' => $result?->status->value ?? self::OFF, 'message' => self::explain($result), 'projects' => []];
        }

        $projects = [];
        foreach (self::rows($result) as $project) {
            $projects[(string) $project['id']] = $project;
        }

        return ['status' => IntegrationStatus::Ok->value, 'message' => null, 'projects' => $projects];
    }

    /**
     * The projects the member may link to, for a picker.
     *
     * @return array{status: string, message: string|null, options: list<array{value: string, label: string}>}
     */
    public static function options(Access $access): array
    {
        $result = self::get($access, 'projects');
        if ($result === null || ! $result->ok()) {
            return ['status' => $result?->status->value ?? self::OFF, 'message' => self::explain($result), 'options' => []];
        }

        return ['status' => IntegrationStatus::Ok->value, 'message' => null, 'options' => array_map(
            fn (array $project) => ['value' => (string) $project['id'], 'label' => (string) $project['label']],
            self::rows($result),
        )];
    }

    /**
     * The organizations active on a project, as Projects answers for this
     * member (ADR-0019 §24), to name the partner a unit's results go to:
     * organization id => name, or null when Projects did not answer.
     *
     * @return array<string, string>|null
     */
    public static function participants(Access $access, string $projectId): ?array
    {
        $result = self::get($access, 'projects/'.rawurlencode($projectId).'/participants');
        if ($result === null || ! $result->ok()) {
            return null;
        }
        $out = [];
        foreach (is_array($result->data['data'] ?? null) ? $result->data['data'] : [] as $row) {
            if (is_array($row) && is_string($row['organizationId'] ?? null) && is_string($row['name'] ?? null)) {
                $label = is_string($row['relationshipLabel'] ?? null) ? " ({$row['relationshipLabel']})" : '';
                $out[strtolower($row['organizationId'])] = $row['name'].$label;
            }
        }

        return $out;
    }

    /**
     * One project, to check a link before it is made.
     */
    public static function project(Access $access, string $projectId): ?IntegrationResult
    {
        return self::get($access, 'projects/'.rawurlencode($projectId));
    }

    /**
     * Milestones by id, or of projects (by project id), as Projects answers
     * for this member, keyed by milestone id.
     *
     * @param  list<string>  $ids
     * @return array{status: string, message: string|null, milestones: array<string, array<string, mixed>>}
     */
    public static function milestones(Access $access, array $ids, bool $ofProjects = false): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return ['status' => IntegrationStatus::Ok->value, 'message' => null, 'milestones' => []];
        }

        $result = self::get($access, 'milestones', [$ofProjects ? 'project_ids' : 'ids' => array_slice($ids, 0, 100)]);
        if ($result === null || ! $result->ok()) {
            return ['status' => $result?->status->value ?? self::OFF, 'message' => self::explain($result), 'milestones' => []];
        }

        $milestones = [];
        foreach (self::rows($result) as $milestone) {
            $milestones[(string) $milestone['id']] = $milestone;
        }

        return ['status' => IntegrationStatus::Ok->value, 'message' => null, 'milestones' => $milestones];
    }

    /**
     * The milestone a result, form or assignment points to: checked in
     * Projects when it changes (a milestone of a project linked to one of
     * the programme's units, that the member may see there). While the
     * link is off, the reference stays as it was: it is never set or
     * cleared without Projects.
     */
    public static function milestoneReference(Access $access, Programme $programme, ?string $current, ?string $given, string $field = 'linked_milestone_id'): ?string
    {
        if (! self::enabled()) {
            return $current;
        }
        $given = $given === null || $given === '' ? null : strtolower($given);
        if ($given === null || $given === $current) {
            return $given;
        }

        $read = self::milestones($access, [$given]);
        if ($read['status'] !== 'ok') {
            throw ValidationException::withMessages([$field => $read['message'] ?? 'Projects is not connected.']);
        }
        $milestone = $read['milestones'][$given] ?? null;
        if ($milestone === null || ! in_array((string) ($milestone['projectId'] ?? ''), self::linkedProjectIds($programme), true)) {
            throw ValidationException::withMessages([$field => 'Choose a milestone of a project linked to one of this programme\'s units.']);
        }

        return $given;
    }

    /**
     * Milestones of the projects linked to the programme's units, and any
     * milestone already pointed to that Projects did not list now, so
     * saving never drops a reference unasked.
     *
     * @param  list<string>  $kept
     * @return list<array{value: string, label: string}>
     */
    public static function milestoneOptions(Access $access, Programme $programme, array $kept): array
    {
        $read = self::enabled() ? self::milestones($access, self::linkedProjectIds($programme), ofProjects: true) : ['milestones' => []];

        $options = array_values(array_map(fn (array $milestone) => ['value' => (string) $milestone['id'], 'label' => (string) $milestone['label']], $read['milestones']));
        foreach (array_diff($kept, array_keys($read['milestones'])) as $id) {
            $options[] = ['value' => $id, 'label' => 'Milestone kept from before (not shown by Projects now)'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function linkedProjectIds(Programme $programme): array
    {
        return array_values($programme->units()->whereNotNull('linked_project_id')->pluck('linked_project_id')->map(fn (mixed $id) => (string) $id)->all());
    }

    /**
     * @param  array<string, mixed>|null  $milestone  as Projects answered
     * @return array{name: string, project: string, dueOn: string|null, reachedOn: string|null}|null
     */
    public static function milestoneSummary(?array $milestone): ?array
    {
        return $milestone === null ? null : [
            'name' => (string) $milestone['name'],
            'project' => (string) $milestone['projectCode'],
            'dueOn' => is_string($milestone['dueOn'] ?? null) ? $milestone['dueOn'] : null,
            'reachedOn' => is_string($milestone['reachedOn'] ?? null) ? $milestone['reachedOn'] : null,
        ];
    }

    /**
     * What to tell the member when Projects did not answer with data.
     */
    public static function explain(?IntegrationResult $result): ?string
    {
        return match ($result?->status) {
            null, IntegrationStatus::Ok, IntegrationStatus::NotConfigured => null,
            IntegrationStatus::NotEntitled => 'Your organization does not have both Projects and M&E, so linked projects cannot be shown.',
            IntegrationStatus::Denied => 'You do not have access to Projects, so linked projects cannot be shown.',
            IntegrationStatus::NotFound => 'That project is not in Projects, or you cannot see it there.',
            IntegrationStatus::Timeout => 'Projects took too long to answer. Linked projects are not shown; try again shortly.',
            IntegrationStatus::Unavailable => 'Projects cannot be reached right now. Linked projects are not shown; try again shortly.',
            IntegrationStatus::Invalid => 'Projects did not accept the request.',
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function get(Access $access, string $path, array $query = []): ?IntegrationResult
    {
        $organizationId = app(CurrentTenant::class)->id();
        $userId = $access->userId();
        if (! self::enabled() || $organizationId === null || $userId === null) {
            return null;
        }

        return app(IntegrationClient::class)->get(self::PAIR, $organizationId, $path, $query, $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(IntegrationResult $result): array
    {
        $data = $result->data['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }
        if (! array_is_list($data)) {
            $data = [$data];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($data, fn (mixed $row) => is_array($row) && isset($row['id'])));
    }
}
