<?php

namespace App\Collect;

use App\Mel\Access;
use App\Models\Collect\Assignment;
use App\Models\Collect\Export;
use App\Models\Collect\Form;
use App\Models\Collect\Submission;
use App\Models\ProgrammeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which forms and collected submissions the signed-in member reaches on
 * the web (ADR-0012 §7), on top of their catalog permissions (checked by
 * the routes):
 *
 * - a form for a programme: when the member reaches the programme (and,
 *   with a grant for one unit, the form's unit);
 * - a form for no programme: org-wide staff (mel.programmes.all);
 * - any form the member is assigned to collect with.
 *
 * Submissions follow their form, and a unit grant also limits them to the
 * unit they were collected for. Anything else answers 404.
 */
class Scope
{
    public function __construct(private readonly Access $access) {}

    public function access(): Access
    {
        return $this->access;
    }

    /**
     * @return Builder<Form>
     */
    public function forms(): Builder
    {
        $query = Form::query();

        if ($this->access->reachesEveryProgramme()) {
            return $query;
        }

        $userId = $this->access->userId();

        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($userId) {
            $query->whereIn('id', Assignment::query()->live()->where('user_id', $userId)->select('form_id'));

            if ($this->access->can('mel.programmes.view')) {
                $query->orWhereExists(fn (QueryBuilder $grants) => $this->grantsFor($grants, $userId, 'collect_forms'));
            }
        });
    }

    public function form(string $id): Form
    {
        return $this->forms()->findOrFail($id);
    }

    /**
     * @return Builder<Submission>
     */
    public function submissions(): Builder
    {
        $query = Submission::query()->whereIn('form_id', $this->forms()->select('id'));

        if ($this->access->reachesEveryProgramme()) {
            return $query;
        }

        $userId = (string) $this->access->userId();

        return $query->where(function (Builder $query) use ($userId) {
            $query->whereNull('programme_id')
                ->orWhereIn('form_id', Assignment::query()->live()->where('user_id', $userId)->select('form_id'))
                ->orWhereExists(fn (QueryBuilder $grants) => $this->grantsFor($grants, $userId, 'collect_submissions'));
        });
    }

    public function submission(string $id): Submission
    {
        return $this->submissions()->findOrFail($id);
    }

    /**
     * The member's own exports of forms they still reach.
     *
     * @return Builder<Export>
     */
    public function exports(): Builder
    {
        return Export::query()
            ->where('requested_by', (string) $this->access->userId())
            ->whereIn('form_id', $this->forms()->select('id'));
    }

    public function seesPersonalData(): bool
    {
        return $this->access->can('mel.collect.personal_data');
    }

    /**
     * Grants in effect for the member on the row's programme that cover
     * the row's unit (a grant without a unit covers them all).
     */
    private function grantsFor(QueryBuilder $grants, string $userId, string $table): void
    {
        $grants->from((new ProgrammeAccess)->getTable())
            ->whereColumn('programme_access.programme_id', "{$table}.programme_id")
            ->whereColumn('programme_access.organization_id', "{$table}.organization_id")
            ->where('programme_access.user_id', $userId)
            ->whereNull('programme_access.revoked_at')
            ->where(fn (QueryBuilder $query) => $query->whereNull('programme_access.expires_on')->orWhere('programme_access.expires_on', '>=', now()->toDateString()))
            ->where(fn (QueryBuilder $query) => $query->whereNull('programme_access.unit_id')
                ->orWhereNull("{$table}.programme_unit_id")
                ->orWhereColumn('programme_access.unit_id', "{$table}.programme_unit_id"));
    }
}
