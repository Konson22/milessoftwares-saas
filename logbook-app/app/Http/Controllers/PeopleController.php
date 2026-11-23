<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Departments;
use App\Logbook\Logbooks;
use App\Logbook\Member;
use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\PendingMember;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 *
 * Which logbooks each person works on is managed here too: chosen when
 * inviting (kept until their first visit) or adding an account, and added
 * or removed on each person. Everything about Logbook's people happens in
 * Logbook; Central is the workspace administrators'.
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

        // Each person's logbooks (those this manager looks after), and those
        // waiting for invitees.
        $managed = Access::managedLogbooks()->select('id');
        $memberships = AccessGrant::query()->whereIn('logbook_id', $managed)
            ->whereIn('user_id', array_column($withRoles, 'sub'))
            ->with('logbook:id,name', 'sheet:id,code')->get()
            ->groupBy('user_id')
            ->map(fn ($grants) => $grants->map(fn (AccessGrant $g) => [
                'grantId' => $g->id,
                'logbookId' => $g->logbook_id,
                'name' => $g->logbook->name,
                'role' => $g->role,
                'sheet' => $g->sheet?->code,
            ])->sortBy('name')->values()->all())
            ->all();
        $pending = PendingMember::query()->whereNotNull('invitation_id')->with('logbook:id,name')->get()
            ->groupBy('invitation_id')
            ->map(fn ($rows) => $rows->pluck('logbook.name')->sort()->values()->all())
            ->all();

        return Inertia::render('people/index', [
            'unavailable' => $unavailable,
            'isOwner' => $isOwner,
            'me' => $this->user()->id,
            'roles' => array_map(fn (array $role) => [...$role, 'assignable' => $isOwner || $role['delegable'], 'membership' => Logbooks::memberRoleForCatalogRole($role['key'])], $roles),
            'logbooks' => self::logbookOptions(),
            'people' => array_values(array_map(fn (array $m) => [
                'membershipId' => $m['membership_id'],
                'userId' => $m['sub'],
                'name' => $m['name'],
                'signInName' => $m['sign_in_name'],
                'isOwner' => $m['is_owner'],
                'access' => $m['application_access'],
                'roles' => self::grouped($m['roles'], $departments),
                'logbooks' => $memberships[$m['sub']] ?? [],
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
                'logbooks' => $pending[$i['id']] ?? [],
            ], $invitations),
            'roleNames' => $names,
            'departments' => Departments::options(self::PERMISSION),
            'mustChooseDepartment' => ! Member::current()->everywhere(self::PERMISSION),
        ]);
    }

    /** The invitation form, on its own page. */
    public function invitePage(): Response
    {
        $isOwner = (bool) app(CurrentAccess::class)->get()?->isOwner;

        return Inertia::render('people/invite', [
            'roles' => array_values(array_filter(
                array_map(fn (array $role) => [...$role, 'assignable' => $isOwner || $role['delegable'], 'membership' => Logbooks::memberRoleForCatalogRole($role['key'])], self::roles()),
                fn (array $role) => $role['assignable'],
            )),
            'logbooks' => self::logbookOptions(),
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
            ...self::logbookRules(),
        ]);
        $logbooks = $this->chosenLogbooks($data);

        return $this->call(function () use ($data, $logbooks) {
            $invitation = $this->platform->invitePerson($this->organizationId(), $this->user()->id, $data['email'], $data['role'], true, $data['department_id'] ?? null);
            foreach ($logbooks as $logbook) {
                PendingMember::query()->updateOrCreate(
                    ['email' => mb_strtolower($data['email']), 'logbook_id' => $logbook->id],
                    ['invitation_id' => $invitation['id'] ?: null, 'granted_by' => $this->user()->id],
                );
            }
        },
            "Invitation sent to {$data['email']}. They join Logbook when they accept it".($logbooks->isEmpty() ? '.' : ', as a member of '.$logbooks->pluck('name')->join(', ', ' and ').'.'),
            // From the invitation page: back to the list once it is sent.
            $request->boolean('from_page') ? route('people.index') : null);
    }

    public function cancelInvitation(string $invitation): RedirectResponse
    {
        return $this->call(function () use ($invitation) {
            $this->platform->cancelInvitation($this->organizationId(), $this->user()->id, $invitation);
            PendingMember::query()->where('invitation_id', $invitation)->delete();
        }, 'Invitation cancelled.');
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
            ...self::logbookRules(),
        ], [
            'username.regex' => 'A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.',
        ]);

        return $this->call(function () use ($data) {
            $result = $this->platform->addMemberAccount($this->organizationId(), $this->user()->id, $data['name'], $data['username'], $data['password'], $data['role'], departmentId: $data['department_id'] ?? null);
            if ($result['role_note'] !== null) {
                throw ValidationException::withMessages(['role' => $result['role_note']]);
            }
            $logbooks = $this->chosenLogbooks($data);
            if ($logbooks->isNotEmpty() && $result['user_id'] !== '') {
                $person = Logbooks::localUser($result['user_id'], $result['name'], $result['username']);
                Logbooks::addPerson($person, $logbooks, $this->user(), $data['role']);
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

    /**
     * Makes a person of Logbook a member of one of the logbooks this manager
     * looks after, in the role their Logbook role suits.
     */
    public function addToLogbook(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'logbook_id' => ['required', 'uuid'],
        ]);
        $logbook = Access::managedLogbook((string) $data['logbook_id']);

        try {
            $person = collect($this->platform->applicationMembers($this->organizationId()))->firstWhere('sub', $data['user_id']);
        } catch (NotVisible|PlatformUnavailable) {
            return back()->withErrors(['people' => 'The platform could not be reached. Try again in a moment.']);
        }
        if ($person === null) {
            return back()->withErrors(['people' => 'That person has no Logbook role in this workspace.']);
        }

        try {
            $user = Logbooks::localUser((string) $person['sub'], (string) $person['name'], (string) $person['sign_in_name']);
            $added = Logbooks::addPerson($user, [$logbook], $this->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['people' => collect($e->errors())->flatten()->first()]);
        }
        if ($added === []) {
            return back()->withErrors(['people' => "{$person['name']}'s Logbook role does not reach {$logbook->name}: it is a manager's, or for another department."]);
        }

        return back()->with('success', "{$person['name']} is now a member of {$logbook->name}.");
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
     * The logbooks this manager looks after, to choose from.
     *
     * @return list<array{id: string, name: string, departmentId: string|null}>
     */
    private static function logbookOptions(): array
    {
        return array_values(Access::managedLogbooks()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'department_id'])
            ->map(fn (Logbook $l) => ['id' => $l->id, 'name' => $l->name, 'departmentId' => $l->department_id])
            ->all());
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function logbookRules(): array
    {
        return [
            'logbook_ids' => ['sometimes', 'array', 'max:50'],
            'logbook_ids.*' => ['uuid'],
        ];
    }

    /**
     * The chosen logbooks: ones this manager looks after, within the
     * department the role is given for (any, for the whole workspace).
     * None when the role is a manager's, who reaches them anyway.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Logbook>
     */
    private function chosenLogbooks(array $data): Collection
    {
        $ids = array_values(array_unique((array) ($data['logbook_ids'] ?? [])));
        if ($ids === [] || Logbooks::memberRoleForCatalogRole((string) $data['role']) === null) {
            return collect();
        }
        $logbooks = Access::managedLogbooks()->where('status', 'active')->whereIn('id', $ids)->get();
        $department = $data['department_id'] ?? null;
        if ($logbooks->count() !== count($ids) || ($department !== null && $logbooks->contains(fn (Logbook $l) => $l->department_id !== $department))) {
            throw ValidationException::withMessages(['logbook_ids' => 'Choose logbooks you look after, in the department the role is for.']);
        }

        return $logbooks;
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
    private function call(Closure $change, string $success, ?string $then = null): RedirectResponse
    {
        try {
            $change();
        } catch (PlatformRefused $e) {
            $errors = $e->errors === [] ? ['people' => [$e->getMessage()]] : $e->errors;
            // The platform's field names, mapped to the forms' (the acting member's problem is the page's).
            $map = ['actor_user_id' => 'people', 'acknowledge_sod' => 'role', 'membership_id' => 'people', 'invitation' => 'people', 'department_id' => 'department_id', 'logbook_ids' => 'logbook_ids'];
            $shown = [];
            foreach ($errors as $field => $messages) {
                $shown[$map[$field] ?? $field] = implode(' ', (array) $messages);
            }

            return back()->withErrors($shown)->withInput();
        } catch (NotVisible|PlatformUnavailable) {
            return back()->withErrors(['people' => 'The platform could not be reached. Try again in a moment.']);
        }

        return ($then !== null ? redirect()->to($then) : back())->with('success', $success);
    }

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
