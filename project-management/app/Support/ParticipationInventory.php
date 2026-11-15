<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0018 step 1: a read-only inventory of every way another organization
 * is involved in a project today, and what a move to project participations
 * would meet. It reads across organizations on purpose (an operator's
 * report) and writes nothing.
 *
 * Channels counted: funders and their portal contacts (donor portal),
 * partnerships (accepted, open, declined, expired), project participations
 * and invitations, M&E partner labels, report obligations, and the
 * historical records that point at each (grant reports, partner reports,
 * requests).
 *
 * Findings, each for a person to resolve, never resolved here:
 *   duplicate_funder / duplicate_mel_partner
 *                    same name, differently written, twice in one directory
 *   funder_matches_organization / mel_partner_matches_organization
 *                    a directory entry whose name is an organization on Tatook
 *   unlinked_funder  a funder with portal contacts and no partnership: an
 *                    off-platform funder (kept on the portal, ADR-0018 §2)
 *   candidate        an accepted partnership whose shared grant funds a
 *                    project: a donor participation step 2 would create
 *   already_participant
 *                    that donor already takes part in that project
 *   visibility_conflict
 *                    the grant's portal switches and the existing
 *                    participation's capabilities disagree
 */
final class ParticipationInventory
{
    /**
     * @return array{counts: array<string, int>, findings: list<array{kind: string, organization: string, subject: string, detail: string}>}
     */
    public static function run(?string $organizationId = null): array
    {
        $names = DB::table('organizations')->pluck('name', 'id');
        $slugs = DB::table('organizations')->pluck('slug', 'id');
        $byName = $names->mapWithKeys(fn (mixed $name, mixed $id) => [self::normalize((string) $name) => (string) $id]);
        $scoped = fn (string $table, string $column = 'organization_id') => DB::table($table)
            ->when($organizationId, fn ($query, $id) => $query->where($column, $id));
        $now = now();

        $counts = [
            'funders' => $scoped('funders')->count(),
            'funder_contacts' => $scoped('funder_contacts')->count(),
            'partnerships_accepted' => $scoped('partnerships', 'beneficiary_organization_id')->whereNotNull('accepted_at')->count(),
            'partnerships_open' => DB::table('partnerships')->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '>', $now)
                ->when($organizationId, fn ($query, $id) => $query->where(fn ($query) => $query->where('donor_organization_id', $id)->orWhere('invited_organization_id', $id)))->count(),
            'partnerships_declined' => DB::table('partnerships')->whereNotNull('declined_at')
                ->when($organizationId, fn ($query, $id) => $query->where(fn ($query) => $query->where('donor_organization_id', $id)->orWhere('invited_organization_id', $id)->orWhere('beneficiary_organization_id', $id)))->count(),
            'partnerships_expired' => DB::table('partnerships')->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '<=', $now)
                ->when($organizationId, fn ($query, $id) => $query->where(fn ($query) => $query->where('donor_organization_id', $id)->orWhere('invited_organization_id', $id)))->count(),
            'participants' => $scoped('project_participants')->count(),
            'participants_active' => $scoped('project_participants')->where('status', 'active')->count(),
            'project_invitations_open' => $scoped('project_invitations')->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')->where('expires_at', '>', $now)->count(),
            'mel_partners' => $scoped('mel_partners')->count(),
            'report_obligations' => $scoped('report_obligations')->count(),
            'grant_reports' => $scoped('grant_reports')->count(),
            'project_reports' => $scoped('project_reports')->count(),
            'project_requests' => $scoped('project_requests')->count(),
        ];

        $findings = [];
        $add = function (string $kind, mixed $organization, string $subject, string $detail) use (&$findings, $slugs) {
            $findings[] = ['kind' => $kind, 'organization' => (string) ($slugs[(string) $organization] ?? $organization), 'subject' => $subject, 'detail' => $detail];
        };

        // Directory entries: duplicates and names that are organizations on Tatook.
        foreach (['funders' => 'funder', 'mel_partners' => 'mel_partner'] as $table => $label) {
            $rows = $scoped($table)->get(['id', 'organization_id', 'name']);
            foreach ($rows->groupBy(fn (object $row) => $row->organization_id.'|'.self::normalize((string) $row->name)) as $group) {
                if ($group->count() > 1) {
                    $add("duplicate_{$label}", $group->first()->organization_id, $group->pluck('name')->implode(' / '), $group->count().' entries');
                }
            }
            foreach ($rows as $row) {
                $match = $byName->get(self::normalize((string) $row->name));
                if ($match !== null && $match !== (string) $row->organization_id) {
                    $add("{$label}_matches_organization", $row->organization_id, (string) $row->name, 'organization '.($slugs[$match] ?? $match).' on Tatook');
                }
            }
        }

        // Funders whose people use the portal, with no partnership behind them.
        $linkedFunders = DB::table('partnerships')->whereNotNull('funder_id')->pluck('funder_id')->flip();
        foreach ($scoped('funders')->whereIn('id', DB::table('funder_contacts')->select('funder_id'))->get(['id', 'organization_id', 'name']) as $funder) {
            if (! $linkedFunders->has($funder->id)) {
                $add('unlinked_funder', $funder->organization_id, (string) $funder->name, DB::table('funder_contacts')->where('funder_id', $funder->id)->count().' portal contact(s); off-platform, stays on the portal');
            }
        }

        // Step 2's mapping: accepted partnership → its funder's shared grants → the projects they fund.
        $partnerships = $scoped('partnerships', 'beneficiary_organization_id')->whereNotNull('accepted_at')->whereNotNull('funder_id')
            ->get(['id', 'donor_organization_id', 'beneficiary_organization_id', 'funder_id']);
        foreach ($partnerships as $partnership) {
            $grants = DB::table('grants')->where('funder_id', $partnership->funder_id)->where('portal_enabled', true)
                ->whereIn('status', ['active', 'closed'])->get(['id', 'reference', 'portal_budget']);
            foreach ($grants as $grant) {
                foreach (DB::table('grant_allocations')->join('projects', 'projects.id', '=', 'grant_allocations.project_id')
                    ->where('grant_allocations.grant_id', $grant->id)->get(['projects.id', 'projects.code']) as $project) {
                    $subject = "{$project->code} ← ".($names[(string) $partnership->donor_organization_id] ?? $partnership->donor_organization_id)." ({$grant->reference})";
                    $existing = DB::table('project_participants')->where('project_id', $project->id)
                        ->where('participant_organization_id', $partnership->donor_organization_id)->first(['relationship', 'capabilities', 'status']);
                    if ($existing === null) {
                        $add('candidate', $partnership->beneficiary_organization_id, $subject, 'donor participation, view'.($grant->portal_budget ? ' + budget.view' : '').", origin partnership {$partnership->id}");

                        continue;
                    }
                    $add('already_participant', $partnership->beneficiary_organization_id, $subject, "{$existing->relationship}, {$existing->status}: add the grant link only");
                    $capabilities = json_decode((string) $existing->capabilities, true);
                    $seesBudget = is_array($capabilities) && in_array('budget.view', $capabilities, true);
                    if ($seesBudget !== (bool) $grant->portal_budget) {
                        $add('visibility_conflict', $partnership->beneficiary_organization_id, $subject, 'portal budget '.($grant->portal_budget ? 'shared' : 'not shared').', participation budget.view '.($seesBudget ? 'given' : 'not given'));
                    }
                }
            }
        }

        return ['counts' => $counts, 'findings' => $findings];
    }

    /**
     * Name as compared: case, accents, punctuation and spacing ignored.
     */
    public static function normalize(string $name): string
    {
        $ascii = (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii)));
    }

    /**
     * @param  list<array{kind: string}>  $findings
     * @return Collection<string, int>
     */
    public static function tally(array $findings): Collection
    {
        return collect($findings)->countBy('kind')->sortKeys();
    }
}
