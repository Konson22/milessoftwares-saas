<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformRefused;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Logbook's people, looked after from Logbook (logbook.people.manage: the
 * Manager and the administrator). Membership and roles live on the
 * platform: every change is a call to it as the signed-in member, and the
 * platform applies the workspace's rules (ApplicationPeopleManagers there).
 * A Manager gives the delegable roles: Contributor, Reviewer, Viewer. The
 * owner-only roles (Manager, Auditor, administrator) are the owner's.
 */
class PeopleController extends Controller
{
    public function __construct(private readonly PlatformApi $platform) {}

    public function index(): Response
    {
        $organization = $this->organizationId();
        $isOwner = (bool) app(CurrentAccess::class)->get()?->isOwner;
        $unavailable = false;

        try {
            $withRoles = $this->platform->applicationMembers($organization);
            $everyone = $this->platform->workspaceMembers($organization);
            $invitations = $this->platform->pendingInvitations($organization);
        } catch (NotVisible|PlatformUnavailable) {
            [$withRoles, $everyone, $invitations, $unavailable] = [[], [], [], true];
        }

        $roles = self::roles();
        $names = array_column($roles, 'name', 'key');
        $listed = array_column($withRoles, 'membership_id');

        return Inertia::render('people/index', [
            'unavailable' => $unavailable,
            'isOwner' => $isOwner,
            'me' => $this->user()->id,
            'roles' => array_map(fn (array $role) => [...$role, 'assignable' => $isOwner || $role['delegable']], $roles),
            'people' => array_values(array_map(fn (array $m) => [
                'membershipId' => $m['membership_id'],
                'userId' => $m['sub'],
                'name' => $m['name'],
                'signInName' => $m['sign_in_name'],
                'isOwner' => $m['is_owner'],
                'access' => $m['application_access'],
                'roles' => array_values(array_unique(array_map(fn (array $r) => $r['role'], $m['roles']))),
            ], array_filter($withRoles, fn (array $m) => $m['membership_status'] === 'active'))),
            'others' => array_values(array_map(fn (array $m) => [
                'membershipId' => $m['membership_id'],
                'name' => $m['name'],
                'signInName' => $m['sign_in_name'],
            ], array_filter($everyone, fn (array $m) => ! $m['is_owner'] && ! in_array($m['membership_id'], $listed, true)))),
            'invitations' => array_map(fn (array $i) => [
                'id' => $i['id'],
                'email' => $i['email'],
                'invitedBy' => $i['invited_by'],
                'expiresAt' => $i['expires_at'],
                'roles' => $i['roles'],
            ], $invitations),
            'roleNames' => $names,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_column(self::roles(), 'key'))],
        ]);

        return $this->call(fn () => $this->platform->invitePerson($this->organizationId(), $this->user()->id, $data['email'], $data['role'], true),
            "Invitation sent to {$data['email']}. They join Logbook when they accept it.");
    }

    public function cancelInvitation(string $invitation): RedirectResponse
    {
        return $this->call(fn () => $this->platform->cancelInvitation($this->organizationId(), $this->user()->id, $invitation), 'Invitation cancelled.');
    }

    /**
     * Someone without an email: a username and a temporary password they
     * replace at first sign-in (the phone app or the web).
     */
    public function addAccount(Request $request): RedirectResponse
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,31}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'role' => ['required', Rule::in(array_column(self::roles(), 'key'))],
        ], [
            'username.regex' => 'A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.',
        ]);

        return $this->call(function () use ($data) {
            $result = $this->platform->addMemberAccount($this->organizationId(), $this->user()->id, $data['name'], $data['username'], $data['password'], $data['role']);
            if ($result['role_note'] !== null) {
                throw ValidationException::withMessages(['role' => $result['role_note']]);
            }
        }, "{$data['name']} can sign in as {$data['username']} with the temporary password you chose, and will pick their own.");
    }

    public function giveRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(array_column(self::roles(), 'key'))],
        ]);

        return $this->call(fn () => $this->platform->giveRole($this->organizationId(), $this->user()->id, $data['membership_id'], $data['role'], true), 'Role given.');
    }

    public function takeBackRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(array_column(self::roles(), 'key'))],
        ]);

        return $this->call(fn () => $this->platform->takeBackRole($this->organizationId(), $this->user()->id, $data['membership_id'], $data['role']), 'Role taken back.');
    }

    /**
     * Logbook's roles, from its catalog (the platform imports the same file).
     *
     * @return list<array{key: string, name: string, description: string, delegable: bool, sensitivity: int}>
     */
    public static function roles(): array
    {
        $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);

        return array_map(fn (array $role) => [
            'key' => (string) $role['key'],
            'name' => (string) $role['name'],
            'description' => (string) ($role['description'] ?? ''),
            'delegable' => (bool) $role['delegable'],
            'sensitivity' => (int) $role['sensitivity'],
        ], $catalog['roles'] ?? []);
    }

    /**
     * Runs a change on the platform; a refusal comes back next to the form.
     */
    private function call(Closure $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (PlatformRefused $e) {
            $errors = $e->errors === [] ? ['people' => [$e->getMessage()]] : $e->errors;
            // The platform's field names, mapped to the forms' (the acting member's problem is the page's).
            $map = ['actor_user_id' => 'people', 'acknowledge_sod' => 'role', 'membership_id' => 'people', 'invitation' => 'people'];
            $shown = [];
            foreach ($errors as $field => $messages) {
                $shown[$map[$field] ?? $field] = implode(' ', (array) $messages);
            }

            return back()->withErrors($shown)->withInput();
        } catch (NotVisible|PlatformUnavailable) {
            return back()->withErrors(['people' => 'The platform could not be reached. Try again in a moment.']);
        }

        return back()->with('success', $success);
    }

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
