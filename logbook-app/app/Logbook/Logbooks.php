<?php

namespace App\Logbook;

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
