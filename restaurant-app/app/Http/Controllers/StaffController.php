<?php

namespace App\Http\Controllers;

use App\Support\Branches;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Who works in the restaurant: each person's roles, where they apply and
 * whether they may sign in. Read from the platform, which holds people,
 * roles and sign-in state for every application: changes are made on the
 * workspace's access page (one place, one audit trail), and take effect in
 * the restaurant at once, live sessions included.
 */
class StaffController extends Controller
{
    public function __invoke(PlatformApi $api, CurrentTenant $tenant, Branches $branches): Response
    {
        abort_unless($branches->managesAccess(), 403, 'Only the restaurant\'s administrators see who has access.');

        $names = $branches->names();
        $unreachable = false;
        try {
            $people = $api->applicationMembers($tenant->require()->id);
        } catch (PlatformUnavailable|NotVisible) {
            // NotVisible: a platform without this list yet, or the workspace gone.
            $people = [];
            $unreachable = true;
        }

        return Inertia::render('staff/index', [
            'unreachable' => $unreachable,
            // The workspace's access page, on this same host.
            'manageUrl' => '/manage/access',
            'people' => array_map(fn (array $person) => [
                'id' => $person['membership_id'],
                'name' => $person['name'],
                'signInName' => $person['sign_in_name'],
                'isOwner' => $person['is_owner'],
                'membership' => $person['membership_status'],
                'signIn' => $person['is_owner'] ? 'active' : $person['application_access'],
                'roles' => array_map(fn (array $role) => [
                    'name' => $role['name'],
                    'where' => $role['scope_type'] === 'branch'
                        ? ($names[$role['branch_id'] ?? ''] ?? 'A closed branch')
                        : 'All branches',
                    'expiresAt' => $role['expires_at'],
                ], $person['roles']),
            ], $people),
        ]);
    }
}
