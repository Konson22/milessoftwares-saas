<?php

namespace App\Http\Controllers\Admin;

use App\Actions\OrgUnits;
use App\Http\Controllers\Controller;
use App\Models\FunderContact;
use App\Models\OrganizationMembership;
use App\Models\OrgUnit;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Members (organization.manage): the people who have
 * opened Projects here, with the roles the platform gives them, and the
 * unit each belongs to. Inviting, roles, suspending and removing are on
 * the platform's members page.
 */
class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = app(CurrentOrganization::class)->require();

        $memberships = $organization->memberships()->with('user:id,name,email,last_login_at')->get()
            ->sortBy(fn (OrganizationMembership $membership) => [! $membership->is_owner, mb_strtolower($membership->user->name)])->values();
        $funders = FunderContact::query()->with('funder:id,name')->whereIn('user_id', $memberships->pluck('user_id'))->get()
            ->mapWithKeys(fn (FunderContact $contact) => [$contact->user_id => $contact->funder->name]);

        return Inertia::render('portal/admin/members', [
            'members' => $memberships->map(fn (OrganizationMembership $membership) => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'isOwner' => $membership->is_owner,
                'funder' => $funders[$membership->user_id] ?? null,
                'status' => $membership->status,
                'unitId' => $membership->org_unit_id,
                'roles' => $membership->roles ?? [],
                'lastLoginAt' => $membership->user->last_login_at?->toIso8601String(),
            ]),
            'units' => OrgUnit::query()->whereNull('archived_at')->orderBy('name')->get()
                ->map(fn (OrgUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            'platformMembersUrl' => Hosts::organization($organization, '/members'),
        ]);
    }

    public function updateUnit(Request $request, string $membership): RedirectResponse
    {
        $found = app(CurrentOrganization::class)->require()->memberships()->with('user')->whereKey($membership)->first();
        abort_if($found === null, 404);
        $validated = $request->validate(['org_unit_id' => ['nullable', 'uuid']]);

        $unit = null;
        if (($validated['org_unit_id'] ?? null) !== null) {
            // Scoped: a unit of another organization is simply not found.
            $unit = OrgUnit::query()->whereKey($validated['org_unit_id'])->first();
            abort_if($unit === null, 422, 'Choose a unit of this organization.');
        }

        OrgUnits::assign($found, $unit, $this->member($request));

        return back()->with('success', "{$found->user->name} updated.");
    }
}
