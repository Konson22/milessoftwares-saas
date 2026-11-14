<?php

namespace App\Mel;

use App\Models\Programme;
use App\Models\ProgrammeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Where the signed-in member may act, on top of what (their catalog
 * permissions, checked by the routes):
 *
 * - **Programmes.** mel.programmes.all reaches every programme; otherwise
 *   only those with a programme_access grant in effect. Anything else
 *   answers 404, as if it did not exist.
 * - **Units.** A grant for one unit limits the member to that unit; a grant
 *   without a unit (or org-wide access) reaches all of them.
 * - **Periods.** A grant with a window (donors, evaluators) limits the
 *   periods to those within it.
 * - **Stage of results.** mel.data.view sees everything entered,
 *   mel.results.view_verified verified and published results,
 *   mel.results.view_published published results only.
 *
 * Nothing here looks at role names: only permissions and grants.
 */
class Access
{
    public const SEE_ALL = 'all';

    public const SEE_VERIFIED = 'verified';

    public const SEE_PUBLISHED = 'published';

    /** @var array<string, Collection<int, ProgrammeAccess>> */
    private array $grants = [];

    public function __construct(
        private readonly CurrentAccess $access,
        private readonly Auth $auth,
    ) {}

    public function can(string $permission): bool
    {
        return $this->access->get()?->has($permission) === true;
    }

    public function userId(): ?string
    {
        $id = $this->auth->guard()->id();

        return $id === null ? null : (string) $id;
    }

    public function reachesEveryProgramme(): bool
    {
        return $this->can('mel.programmes.all');
    }

    /**
     * The programmes the member can open at all.
     *
     * @return Builder<Programme>
     */
    public function programmes(): Builder
    {
        $query = Programme::query();

        if ($this->reachesEveryProgramme()) {
            return $query;
        }

        if (! $this->can('mel.programmes.view') || $this->userId() === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', ProgrammeAccess::query()->inEffect()->where('user_id', $this->userId())->select('programme_id'));
    }

    /**
     * One programme the member can open, or 404.
     */
    public function programme(string $id): Programme
    {
        return $this->programmes()->findOrFail($id);
    }

    /**
     * The member's grants on the programme (empty with org-wide access).
     *
     * @return Collection<int, ProgrammeAccess>
     */
    public function grants(Programme $programme): Collection
    {
        if ($this->reachesEveryProgramme() || $this->userId() === null) {
            return collect();
        }

        return $this->grants[$programme->id] ??= ProgrammeAccess::query()->inEffect()
            ->where('programme_id', $programme->id)
            ->where('user_id', $this->userId())
            ->get()
            ->toBase();
    }

    /**
     * The units the member reaches in the programme; null for all of them.
     *
     * @return list<string>|null
     */
    public function unitIds(Programme $programme): ?array
    {
        $grants = $this->grants($programme);

        if ($this->reachesEveryProgramme() || $grants->contains(fn (ProgrammeAccess $grant) => $grant->unit_id === null)) {
            return null;
        }

        return array_values($grants->map(fn (ProgrammeAccess $grant) => $grant->unit_id)->filter(fn (?string $id) => $id !== null)->unique()->all());
    }

    public function reachesUnit(Programme $programme, string $unitId): bool
    {
        $units = $this->unitIds($programme);

        return $units === null || in_array($unitId, $units, true);
    }

    /**
     * The window of periods the member may see: [from, to], either end
     * open (null); null when not limited.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}|null
     */
    public function periodWindow(Programme $programme): ?array
    {
        $grants = $this->grants($programme);

        if ($this->reachesEveryProgramme() || $grants->contains(fn (ProgrammeAccess $grant) => $grant->periods_from === null && $grant->periods_to === null)) {
            return null;
        }

        $from = $grants->contains(fn (ProgrammeAccess $grant) => $grant->periods_from === null) ? null : $grants->min('periods_from');
        $to = $grants->contains(fn (ProgrammeAccess $grant) => $grant->periods_to === null) ? null : $grants->max('periods_to');

        return [$from, $to];
    }

    /**
     * Whether a period (by its dates) is within the member's window.
     */
    public function reachesPeriod(Programme $programme, CarbonImmutable $startsOn, CarbonImmutable $endsOn): bool
    {
        $window = $this->periodWindow($programme);

        if ($window === null) {
            return true;
        }

        [$from, $to] = $window;

        return ($from === null || $startsOn->greaterThanOrEqualTo($from)) && ($to === null || $endsOn->lessThanOrEqualTo($to));
    }

    /**
     * The furthest stage of results the member may see, or null for none.
     */
    public function sees(): ?string
    {
        return match (true) {
            $this->can('mel.data.view') => self::SEE_ALL,
            $this->can('mel.results.view_verified') => self::SEE_VERIFIED,
            $this->can('mel.results.view_published') => self::SEE_PUBLISHED,
            default => null,
        };
    }
}
