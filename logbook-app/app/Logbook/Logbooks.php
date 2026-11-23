<?php

namespace App\Logbook;

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\PendingMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\PlatformUnavailable;

/**
 * Logbooks and who reaches a restricted one. Logbooks are archived, never
 * deleted: they hold operational records.
 */
final class Logbooks
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data, User $by): Logbook
    {
        return DB::transaction(function () use ($data, $by) {
            $logbook = Logbook::query()->create([...$data, 'status' => 'active', 'created_by' => $by->id]);
            AuditTrail::record('logbook.created', $logbook, $by, ['restricted' => $logbook->restricted]);

            return $logbook;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(Logbook $logbook, array $data, User $by): void
    {
        DB::transaction(function () use ($logbook, $data, $by) {
            $logbook->fill($data);
            $changed = array_keys($logbook->getDirty());
            $logbook->save();
            if ($changed !== []) {
                AuditTrail::record(in_array('restricted', $changed, true) ? 'logbook.access_changed' : 'logbook.updated', $logbook, $by, [
                    'changed' => $changed,
                    'restricted' => in_array('restricted', $changed, true) ? $logbook->restricted : null,
                ]);
            }
        });
    }

    public static function archive(Logbook $logbook, User $by): void
    {
        DB::transaction(function () use ($logbook, $by) {
            $logbook->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
            AuditTrail::record('logbook.archived', $logbook, $by);
        });
    }

    public static function restore(Logbook $logbook, User $by): void
    {
        DB::transaction(function () use ($logbook, $by) {
            $logbook->forceFill(['status' => 'active', 'archived_at' => null])->save();
            AuditTrail::record('logbook.restored', $logbook, $by);
        });
    }

    public static function grant(Logbook $logbook, string $userId, string $role, ?LogSheet $sheet, User $by, bool $check = true): AccessGrant
    {
        if ($sheet !== null && $sheet->logbook_id !== $logbook->id) {
            throw ValidationException::withMessages(['log_sheet_id' => 'That sheet is not in this logbook.']);
        }
        if ($check) {
            self::mustCover($logbook, $userId, $role);
        }

        return DB::transaction(function () use ($logbook, $userId, $role, $sheet, $by) {
            $grant = AccessGrant::query()->where('logbook_id', $logbook->id)->where('user_id', $userId)
                ->where('log_sheet_id', $sheet?->id)->lockForUpdate()->first();
            $before = $grant?->role;

            $grant ??= new AccessGrant(['logbook_id' => $logbook->id, 'user_id' => $userId, 'log_sheet_id' => $sheet?->id]);
            $grant->fill(['role' => $role, 'granted_by' => $by->id])->save();

            AuditTrail::record('logbook.access_granted', $logbook, $by, [
                'member' => User::query()->whereKey($userId)->value('name'),
                'role' => $role,
                'previous_role' => $before,
                'sheet' => $sheet?->code,
            ]);

            return $grant;
        });
    }

    /**
     * The membership a catalog role makes useful: writers write, reviewers
     * review, readers read. Null for managers and administrators, who reach
     * their departments' logbooks without being members.
     */
    public static function memberRoleForCatalogRole(string $roleKey): ?string
    {
        static $catalog = null;
        $catalog ??= json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);
        foreach ($catalog['roles'] ?? [] as $role) {
            if (($role['key'] ?? null) !== $roleKey) {
                continue;
            }
            $permissions = (array) ($role['permissions'] ?? []);
            if (in_array(Access::MANAGE, $permissions, true)) {
                return null;
            }
            foreach (self::MEMBER_PERMISSIONS as $member => [$permission]) {
                if (in_array($permission, $permissions, true)) {
                    return $member;
                }
            }
        }

        return null;
    }

    /** The first membership the person's Logbook role allows in this logbook's department, or null. */
    public static function memberRoleFor(Member $member, Logbook $logbook): ?string
    {
        foreach (self::MEMBER_PERMISSIONS as $role => [$permission]) {
            $reach = $member->reach($permission);
            if ($member->can($permission) && ($reach === null || ($logbook->department_id !== null && in_array($logbook->department_id, $reach, true)))) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The local record of a workspace member who has not opened Logbook
     * yet, so they can be made a member of logbooks now. Name and sign-in
     * name are refreshed at their first sign-in.
     */
    public static function localUser(string $id, string $name, string $signInName): User
    {
        $user = User::query()->find($id);
        if ($user === null) {
            $user = new User;
            $user->forceFill(['id' => $id, 'name' => $name, 'email' => $signInName])->save();
        }

        return $user;
    }

    /**
     * Makes someone a member of logbooks in the role their Logbook role
     * suits. With $roleKey (a role just given, not yet visible from the
     * platform) the membership follows that role; otherwise their current
     * role decides, logbook by logbook. Returns the logbook names added.
     *
     * @param  iterable<Logbook>  $logbooks
     * @return list<string>
     */
    public static function addPerson(User $person, iterable $logbooks, User $by, ?string $roleKey = null): array
    {
        $added = [];
        $member = $roleKey === null ? Member::of((string) $person->id) : null;
        foreach ($logbooks as $logbook) {
            $role = $roleKey !== null ? self::memberRoleForCatalogRole($roleKey) : ($member === null ? null : self::memberRoleFor($member, $logbook));
            if ($role === null) {
                continue;
            }
            self::grant($logbook, (string) $person->id, $role, null, $by, check: $roleKey === null);
            $added[] = $logbook->name;
        }

        return $added;
    }

    /**
     * On someone's first visit: the logbooks chosen for them when they were
     * invited become memberships, in the role their Logbook role suits.
     */
    public static function applyPending(User $person): void
    {
        $pending = PendingMember::query()->whereRaw('email = ?', [mb_strtolower($person->email)])->with('logbook')->get();
        if ($pending->isEmpty()) {
            return;
        }

        $member = Member::of((string) $person->id);
        DB::transaction(function () use ($pending, $member, $person) {
            foreach ($pending as $row) {
                $role = $member === null ? null : self::memberRoleFor($member, $row->logbook);
                $by = User::query()->find($row->granted_by);
                if ($role !== null && $by !== null && ! AccessGrant::query()->where('logbook_id', $row->logbook_id)->where('user_id', $person->id)->whereNull('log_sheet_id')->exists()) {
                    self::grant($row->logbook, (string) $person->id, $role, null, $by, check: false);
                }
                $row->delete();
            }
        });
    }

    /** What each membership role needs from the person's Logbook role. */
    public const MEMBER_PERMISSIONS = [
        'contributor' => ['logbook.entries.create', 'write entries'],
        'reviewer' => ['logbook.entries.review', 'review entries'],
        'viewer' => ['logbook.view', 'read entries'],
    ];

    /**
     * A membership only works with a Logbook role that allows it in the
     * logbook's department, so one that would do nothing is refused with
     * the reason.
     */
    private static function mustCover(Logbook $logbook, string $userId, string $role): void
    {
        $name = User::query()->whereKey($userId)->value('name') ?? 'This person';
        try {
            $member = Member::of($userId);
        } catch (PlatformUnavailable) {
            throw ValidationException::withMessages(['user_id' => 'The platform could not be reached to check this person\'s Logbook role. Try again in a moment.']);
        }
        if ($member === null) {
            throw ValidationException::withMessages(['user_id' => "{$name} has no Logbook role in this workspace."]);
        }

        [$permission, $doing] = self::MEMBER_PERMISSIONS[$role];
        if (! $member->can($permission)) {
            throw ValidationException::withMessages(['role' => "{$name}'s Logbook role does not let them {$doing}."]);
        }

        $reach = $member->reach($permission);
        if ($reach !== null && ($logbook->department_id === null || ! in_array($logbook->department_id, $reach, true))) {
            throw ValidationException::withMessages(['user_id' => $logbook->department_id === null
                ? "{$name}'s Logbook role covers some departments only; this logbook belongs to the whole workspace."
                : "{$name}'s Logbook role does not cover this logbook's department."]);
        }
    }

    public static function revoke(AccessGrant $grant, User $by): void
    {
        DB::transaction(function () use ($grant, $by) {
            $logbook = Logbook::query()->findOrFail($grant->logbook_id);
            AuditTrail::record('logbook.access_revoked', $logbook, $by, [
                'member' => $grant->user->name,
                'role' => $grant->role,
                'sheet' => $grant->sheet?->code,
            ]);
            $grant->delete();
        });
    }
}
