<?php

namespace App\Mel;

use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeAccess;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ResultLevel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programmes, their reporting units and who has access to them. Every
 * change is audited in its own transaction.
 */
class Programmes
{
    /** Who a unit's results are released to in project reports (ADR-0019 §24). */
    public const AUDIENCES = ['owner', 'partner', 'donors'];

    private const OWNER_ONLY = ['project_report_audience' => 'owner', 'project_report_partner_organization_id' => null, 'project_report_partner_name' => null];

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{code: string, name: string, objective?: string|null, description?: string|null, starts_on: string, ends_on: string, currency: string, budget_minor?: int|null, funding_summary?: string|null, manager_id?: string|null, location_ids?: list<string>}  $data
     */
    public function create(array $data, User $user): Programme
    {
        return DB::transaction(function () use ($data, $user) {
            $programme = Programme::query()->create([
                ...collect($data)->except('location_ids')->all(),
                'status' => Programme::DRAFT,
                'created_by' => $user->id,
            ]);

            foreach (ResultLevel::DEFAULTS as $i => $name) {
                ResultLevel::query()->create(['programme_id' => $programme->id, 'depth' => $i + 1, 'name' => $name]);
            }

            $this->syncLocations($programme, $data['location_ids'] ?? []);
            $this->audit->record('programme.created', $programme, $user);

            return $programme;
        });
    }

    /**
     * @param  array{code: string, name: string, objective?: string|null, description?: string|null, starts_on: string, ends_on: string, currency: string, budget_minor?: int|null, funding_summary?: string|null, manager_id?: string|null, status: string, location_ids?: list<string>}  $data
     */
    public function update(Programme $programme, array $data, User $user): void
    {
        $this->assertNotArchived($programme);

        DB::transaction(function () use ($programme, $data, $user) {
            $programme->fill(collect($data)->except('location_ids')->all());
            $changed = array_keys($programme->getDirty());
            $programme->save();
            $this->syncLocations($programme, $data['location_ids'] ?? []);
            $this->audit->record('programme.updated', $programme, $user, ['changed' => $changed ?: null]);
        });
    }

    /**
     * Archived programmes keep everything and show read-only.
     */
    public function archive(Programme $programme, User $user): void
    {
        DB::transaction(function () use ($programme, $user) {
            $programme->update(['archived_at' => now()]);
            $this->audit->record('programme.archived', $programme, $user);
        });
    }

    public function restore(Programme $programme, User $user): void
    {
        DB::transaction(function () use ($programme, $user) {
            $programme->update(['archived_at' => null]);
            $this->audit->record('programme.restored', $programme, $user);
        });
    }

    /**
     * @param  array{code: string, name: string, kind: string, partner_id?: string|null, location_id?: string|null}  $data
     */
    public function saveUnit(Programme $programme, ?ProgrammeUnit $unit, array $data, User $user): ProgrammeUnit
    {
        $this->assertNotArchived($programme);

        return DB::transaction(function () use ($programme, $unit, $data, $user) {
            $unit ??= new ProgrammeUnit(['programme_id' => $programme->id]);
            $created = ! $unit->exists;
            $unit->fill($data)->save();
            $this->audit->record($created ? 'unit.created' : 'unit.updated', $unit, $user, ['programme' => $programme->code]);

            return $unit;
        });
    }

    public function archiveUnit(ProgrammeUnit $unit, bool $archived, User $user): void
    {
        DB::transaction(function () use ($unit, $archived, $user) {
            $unit->update(['archived_at' => $archived ? now() : null]);
            $this->audit->record($archived ? 'unit.archived' : 'unit.restored', $unit, $user);
        });
    }

    /**
     * Connects a unit to a project from Tatook Projects (or disconnects it
     * with null). The project stays Projects' record (the caller has checked
     * it there); one project is linked to at most one unit of a programme.
     * Linking again to the same project changes nothing.
     */
    public function linkProject(ProgrammeUnit $unit, ?string $projectId, User $user, bool $shares = false): void
    {
        $this->assertNotArchived($unit->programme);
        $projectId = $projectId === null ? null : strtolower($projectId);
        // Sharing with the project's partner reports only means something
        // while linked; a new project starts unshared unless said otherwise.
        $shares = $projectId !== null && $shares;
        if ($unit->linked_project_id === $projectId) {
            if ((bool) $unit->shares_with_project_reports !== $shares) {
                DB::transaction(function () use ($unit, $shares, $user) {
                    // Switched off, the release is withdrawn too: switching on again starts at the owner only.
                    $unit->update(['shares_with_project_reports' => $shares, ...($shares ? [] : self::OWNER_ONLY)]);
                    $this->audit->record($shares ? 'unit.project_reporting_on' : 'unit.project_reporting_off', $unit, $user, ['project' => $unit->linked_project_id]);
                });
            }

            return;
        }

        DB::transaction(function () use ($unit, $projectId, $user, $shares) {
            if ($projectId !== null && ProgrammeUnit::query()
                ->where('programme_id', $unit->programme_id)
                ->where('linked_project_id', $projectId)
                ->whereKeyNot($unit->id)
                ->exists()) {
                throw ValidationException::withMessages(['linked_project_id' => 'That project is already linked to another unit of this programme.']);
            }

            // A partner named for the old project means nothing on the new one.
            $unit->update(['linked_project_id' => $projectId, 'shares_with_project_reports' => $shares, ...self::OWNER_ONLY]);
            $this->audit->record($projectId === null ? 'unit.project_unlinked' : 'unit.project_linked', $unit, $user, ['project' => $projectId, 'project_reporting' => $shares]);
        });
    }

    /**
     * Who the unit's published results are released to in its linked
     * project's partner reports (ADR-0019 §24): the project owner only, also
     * a named partner organization (on reports it submits, and to the party
     * reviewing them), or also donors on published reports. An M&E
     * decision, separate from verifying and publishing results in M&E and
     * from publishing a report in Projects. The caller has checked, in
     * Projects, that the partner is active on the project. It applies to
     * reports submitted from now on; reports already submitted keep what
     * they recorded.
     */
    public function setProjectAudience(ProgrammeUnit $unit, string $audience, ?string $partnerId, ?string $partnerName, User $user): void
    {
        $this->assertNotArchived($unit->programme);
        if ($unit->linked_project_id === null || ! $unit->shares_with_project_reports) {
            throw ValidationException::withMessages(['audience' => 'Link the unit to a project and report its results there first.']);
        }
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw ValidationException::withMessages(['audience' => 'Choose who sees the results.']);
        }
        if ($audience === 'partner' && $partnerId === null) {
            throw ValidationException::withMessages(['partner_organization_id' => 'Choose the partner organization.']);
        }
        $partnerId = $audience === 'owner' || $partnerId === null ? null : strtolower($partnerId);
        $partnerName = $partnerId === null ? null : $partnerName;

        $before = ['audience' => $unit->project_report_audience, 'partner' => $unit->project_report_partner_organization_id];
        if ($before === ['audience' => $audience, 'partner' => $partnerId]) {
            return;
        }

        DB::transaction(function () use ($unit, $audience, $partnerId, $partnerName, $user, $before) {
            $unit->update([
                'project_report_audience' => $audience,
                'project_report_partner_organization_id' => $partnerId,
                'project_report_partner_name' => $partnerName,
            ]);
            $this->audit->record('unit.project_audience_changed', $unit, $user, [
                'project' => $unit->linked_project_id,
                'from' => $before,
                'to' => ['audience' => $audience, 'partner' => $partnerId, 'partner_name' => $partnerName],
            ]);
        });
    }

    /**
     * @param  array{user_id: string, unit_id?: string|null, periods_from?: string|null, periods_to?: string|null, expires_on?: string|null}  $data
     */
    public function grant(Programme $programme, array $data, User $user): ProgrammeAccess
    {
        $this->assertNotArchived($programme);

        if (($data['unit_id'] ?? null) !== null && ! $programme->units()->whereKey($data['unit_id'])->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this programme.']);
        }

        // Access combines a person's units with their period window, so all
        // their grants on one programme share one window: otherwise a
        // one-quarter grant for one unit would open every quarter of it.
        $from = ($data['periods_from'] ?? null) === null ? null : CarbonImmutable::parse($data['periods_from'])->toDateString();
        $to = ($data['periods_to'] ?? null) === null ? null : CarbonImmutable::parse($data['periods_to'])->toDateString();
        $other = ProgrammeAccess::query()->inEffect()
            ->where('programme_id', $programme->id)->where('user_id', $data['user_id'])->get()
            ->first(fn (ProgrammeAccess $grant) => $grant->periods_from?->toDateString() !== $from || $grant->periods_to?->toDateString() !== $to);
        if ($other !== null) {
            throw ValidationException::withMessages(['periods_from' => 'This person already has access here for other periods. Use the same periods, or remove that access first.']);
        }

        return DB::transaction(function () use ($programme, $data, $user) {
            $grant = ProgrammeAccess::query()->create([...$data, 'programme_id' => $programme->id, 'granted_by' => $user->id]);
            $this->audit->record('access.granted', $programme, $user, [
                'user' => $grant->user->name,
                'unit' => $grant->unit?->code,
                'periods_from' => $data['periods_from'] ?? null,
                'periods_to' => $data['periods_to'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
            ]);

            return $grant;
        });
    }

    public function revoke(ProgrammeAccess $grant, User $user): void
    {
        if ($grant->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($grant, $user) {
            $grant->update(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $user->id]);
            $this->audit->record('access.revoked', $grant->programme, $user, ['user' => $grant->user->name, 'unit' => $grant->unit?->code]);
        });
    }

    public function assertNotArchived(Programme $programme): void
    {
        if ($programme->isArchived()) {
            throw ValidationException::withMessages(['programme' => 'This programme is archived. Restore it to make changes.']);
        }
    }

    /**
     * @param  list<string>  $locationIds
     */
    private function syncLocations(Programme $programme, array $locationIds): void
    {
        $programme->locations()->sync(collect($locationIds)->unique()->mapWithKeys(fn (string $id) => [$id => ['organization_id' => $programme->organization_id]])->all());
    }
}
