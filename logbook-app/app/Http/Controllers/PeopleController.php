<?php

namespace App\Http\Controllers;

use App\Logbook\Departments;
use App\Logbook\Member;
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
 *
 * A role is given for the whole workspace or for one department: then the
 * person reaches only that department's logbooks. A people manager whose
 * own role is limited to departments gives roles in those departments only
 * (the platform checks it too).
 */
class PeopleController extends Controller
{
    public const PERMISSION = 'logbook.people.manage';

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
        $departments = Departments::names();

        // A people manager for some departments sees the people working in them.
        $reach = Member::current()->reach(self::PERMISSION);
        if ($reach !== null) {
            $withRoles = array_values(array_filter($withRoles, fn (array $m) => array_filter(
                $m['roles'],
                fn (array $r) => in_array((string) ($r['department_id'] ?? ''), $reach, true),
            ) !== []));
        }

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
                'roles' => self::grouped($m['roles'], $departments),
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
            'departments' => Departments::options(self::PERMISSION),
            'mustChooseDepartment' => ! Member::current()->everywhere(self::PERMISSION),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_column(self::roles(), 'key'))],
            'department_id' => self::departmentRule(),
        ]);

        return $this->call(fn () => $this->platform->invitePerson($this->organizationId(), $this->user()->id, $data['email'], $data['role'], true, $data['department_id'] ?? null),
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
            'department_id' => self::departmentRule(),
        ], [
            'username.regex' => 'A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.',
        ]);

        return $this->call(function () use ($data) {
            $result = $this->platform->addMemberAccount($this->organizationId(), $this->user()->id, $data['name'], $data['username'], $data['password'], $data['role'], departmentId: $data['department_id'] ?? null);
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
            'department_id' => self::departmentRule(),
        ]);

        return $this->call(fn () => $this->platform->giveRole($this->organizationId(), $this->user()->id, $data['membership_id'], $data['role'], true, $data['department_id'] ?? null), 'Role given.');
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

        return array_values(array_map(fn (array $role) => [
            'key' => (string) $role['key'],
            'name' => (string) $role['name'],
            'description' => (string) ($role['description'] ?? ''),
            'delegable' => (bool) $role['delegable'],
            'sensitivity' => (int) $role['sensitivity'],
        ], $catalog['roles'] ?? []));
    }

    /**
     * Empty: the whole workspace, which only a people manager for the whole
     * workspace may give. Otherwise one of the departments they look after.
     *
     * @return list<mixed>
     */
    private static function departmentRule(): array
    {
        return [
            Member::current()->everywhere(self::PERMISSION) ? 'nullable' : 'required',
            'uuid',
            Rule::in(array_column(Departments::options(self::PERMISSION), 'id')),
        ];
    }

    /**
     * A person's roles, one per role key, with the departments it applies
     * in (null: the whole workspace).
     *
     * @param  list<array<string, mixed>>  $assignments
     * @param  array<string, string>  $departments
     * @return list<array{key: string, departments: list<string>|null}>
     */
    private static function grouped(array $assignments, array $departments): array
    {
        $roles = [];
        foreach ($assignments as $assignment) {
            $key = (string) $assignment['role'];
            $type = (string) ($assignment['scope_type'] ?? 'organization');
            $where = match ($type) {
                'department' => $departments[(string) ($assignment['department_id'] ?? '')] ?? 'a removed department',
                'branch' => 'a branch',
                default => null,
            };

            if (! array_key_exists($key, $roles)) {
                $roles[$key] = ['key' => $key, 'departments' => []];
            }
            if ($where === null || $roles[$key]['departments'] === null) {
                $roles[$key]['departments'] = null;
            } elseif (! in_array($where, $roles[$key]['departments'], true)) {
                $roles[$key]['departments'][] = $where;
            }
        }

        return array_values($roles);
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
            $map = ['actor_user_id' => 'people', 'acknowledge_sod' => 'role', 'membership_id' => 'people', 'invitation' => 'people', 'department_id' => 'department_id'];
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
