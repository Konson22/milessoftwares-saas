<?php

namespace App\Logbook;

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
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

    public static function grant(Logbook $logbook, string $userId, string $role, ?LogSheet $sheet, User $by): AccessGrant
    {
        if ($sheet !== null && $sheet->logbook_id !== $logbook->id) {
            throw ValidationException::withMessages(['log_sheet_id' => 'That sheet is not in this logbook.']);
        }
        self::mustCover($logbook, $userId, $role);

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
