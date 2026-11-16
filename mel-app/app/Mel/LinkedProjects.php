<?php

namespace App\Mel;

use App\Mel\Models\ProgrammeUnit;
use Illuminate\Support\Collection;

/**
 * The link to Tatook Projects (ADR-0019 §9). Projects stays the record of
 * projects and milestones; M&E keeps only their ids on units and results
 * (`linked_project_id`, `linked_milestone_id`, no foreign key across
 * applications).
 *
 * Off in this release (ADR-0019 M1): nothing is read from Projects, no new
 * link can be made, and linked sections are hidden. Milestone M3 reads
 * Projects' integration API here, for organizations entitled to both apps
 * and members allowed to see the project (projects.view, and
 * projects.finance.view for money). Every M&E workflow works without it.
 */
final class LinkedProjects
{
    public static function enabled(): bool
    {
        return false;
    }

    /**
     * Figures for each linked project the member may see, by project id.
     * Empty while the link is off.
     *
     * @param  Collection<int, ProgrammeUnit>  $units
     * @return array<string, array{id: string, label: string, status: string, progressPercent: int|null, currency: string, approvedBudgetMinor: int|null, paidMinor: int|null, paidStatus: string|null}>
     */
    public static function figures(Collection $units, Access $access): array
    {
        return [];
    }
}
