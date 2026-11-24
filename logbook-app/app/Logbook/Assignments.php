<?php

namespace App\Logbook;

use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\ResourceAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who an asset is assigned to (ADR-0032 §3): a vehicle's drivers. Only
 * managers assign. A sheet set to "assigned only" lets a person record an
 * asset only on a day it is assigned to them; the server checks it at
 * submission, whatever the form offered.
 */
final class Assignments
{
    /**
     * Assigns the asset to a person from a day, optionally to a day.
     *
     * @param  array{user_id?: mixed, starts_on?: mixed, ends_on?: mixed, note?: mixed}  $data
     */
    public static function assign(LogResource $resource, array $data, User $by): ResourceAssignment
    {
        $userId = is_string($data['user_id'] ?? null) ? $data['user_id'] : '';
        $starts = self::day($data['starts_on'] ?? null) ?? self::today();
        $ends = self::day($data['ends_on'] ?? null);
        $errors = [];
        if ($resource->status === 'retired') {
            $errors['user_id'] = 'A retired asset is not assigned to anyone.';
        } elseif ($userId === '' || ! WorkspaceMember::query()->ofUser($userId)->exists()) {
            $errors['user_id'] = 'Choose a person of this workspace.';
        }
        if (($data['starts_on'] ?? null) !== null && ($data['starts_on'] ?? '') !== '' && self::day($data['starts_on']) === null) {
            $errors['starts_on'] = 'Give the first day as a date.';
        }
        if (($data['ends_on'] ?? '') !== '' && ($data['ends_on'] ?? null) !== null && $ends === null) {
            $errors['ends_on'] = 'Give the last day as a date.';
        } elseif ($ends !== null && $ends < $starts) {
            $errors['ends_on'] = 'The last day is before the first day.';
        }
        if ($errors === [] && self::overlapping($resource->id, $userId, $starts, $ends)) {
            $errors['user_id'] = 'This person is already assigned to this asset on some of those days.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($resource, $userId, $starts, $ends, $data, $by) {
            $assignment = ResourceAssignment::query()->create([
                'log_resource_id' => $resource->id,
                'user_id' => $userId,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'note' => is_string($data['note'] ?? null) && trim($data['note']) !== '' ? mb_substr(trim($data['note']), 0, 500) : null,
                'created_by' => $by->id,
            ]);
            AuditTrail::record('resource.assigned', $resource, $by, [
                'user_id' => $userId, 'user' => Tasks::name($userId), 'from' => $starts, 'to' => $ends,
            ]);

            return $assignment;
        });
    }

    /**
     * Ends an assignment: its last day becomes today (the person may still
     * record today's trips). One that has not started yet is cancelled.
     */
    public static function end(ResourceAssignment $assignment, User $by): ResourceAssignment
    {
        if ($assignment->ended_at !== null) {
            throw ValidationException::withMessages(['assignment' => 'This assignment has already ended.']);
        }
        $today = self::today();
        $starts = $assignment->starts_on->toDateString();
        if ($assignment->ends_on !== null && $assignment->ends_on->toDateString() < $today) {
            throw ValidationException::withMessages(['assignment' => 'This assignment is already over.']);
        }

        return DB::transaction(function () use ($assignment, $by, $today, $starts) {
            // Not started yet: it never covers a day (the day before its first).
            $last = $starts > $today ? CarbonImmutable::parse($starts)->subDay()->toDateString() : $today;
            $assignment->forceFill(['ends_on' => $last, 'ended_at' => now(), 'ended_by' => $by->id])->save();
            AuditTrail::record($starts > $today ? 'resource.assignment_cancelled' : 'resource.assignment_ended', $assignment->resource, $by, [
                'user_id' => $assignment->user_id, 'user' => Tasks::name($assignment->user_id), 'to' => $last,
            ]);

            return $assignment;
        });
    }

    /**
     * Ids of the assets assigned to a person on a day.
     *
     * @return list<string>
     */
    public static function assetIdsFor(string $userId, string $day): array
    {
        $standing = array_map('strval', ResourceAssignment::query()->where('user_id', $userId)
            ->whereDate('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->pluck('log_resource_id')->all());

        // ADR-0032 §4: a planned trip assigns its vehicle to its driver for its day.
        return array_values(array_unique([...$standing, ...Dispatches::assetIdsFor($userId, $day)]));
    }

    public static function allows(string $resourceId, string $userId, string $day): bool
    {
        return in_array($resourceId, self::assetIdsFor($userId, $day), true);
    }

    /**
     * A person's assignments that are not over, for their phone: it checks
     * the day offline the way the server does.
     *
     * @return list<array{resourceId: string, from: string, to: string|null}>
     */
    public static function currentFor(string $userId): array
    {
        $today = self::today();

        return array_values(ResourceAssignment::query()->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')->get()
            ->filter(fn (ResourceAssignment $a) => $a->ends_on === null || $a->ends_on->greaterThanOrEqualTo($a->starts_on))
            ->map(fn (ResourceAssignment $a) => ['resourceId' => $a->log_resource_id, 'from' => $a->starts_on->toDateString(), 'to' => $a->ends_on?->toDateString()])
            ->all());
    }

    /** The sheet lets only the people an asset is assigned to record it. */
    public static function required(?LogSheetVersion $version): bool
    {
        return ($version?->recording['assigned_only'] ?? false) === true;
    }

    /**
     * Refuses an entry whose asset is not assigned to its author on the
     * day the activity started (ADR-0032 §3.3).
     *
     * @param  array<string, mixed>  $values
     */
    public static function check(LogSheetVersion $version, array $values, string $authorId, CarbonInterface $at): void
    {
        if (! self::required($version)) {
            return;
        }
        $field = Recording::subjectField($version);
        $resourceId = $field === null ? null : ($values[$field] ?? null);
        if (! is_string($resourceId) || $resourceId === '') {
            return;
        }
        $day = self::localDay($at);
        if (! self::allows($resourceId, $authorId, $day)) {
            $code = LogResource::query()->whereKey($resourceId)->value('code') ?? 'This asset';
            throw ValidationException::withMessages([
                "values.{$field}" => "{$code} is not assigned to you on ".CarbonImmutable::parse($day)->format('j M Y').'. A manager assigns vehicles and other assets.',
            ]);
        }
    }

    public static function localDay(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(self::tz())->toDateString();
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::tz())->toDateString();
    }

    private static function tz(): string
    {
        return Workspace::current()->timezone ?? 'UTC';
    }

    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    private static function overlapping(string $resourceId, string $userId, string $starts, ?string $ends): bool
    {
        return ResourceAssignment::query()->where('log_resource_id', $resourceId)->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $starts))
            ->when($ends !== null, fn ($q) => $q->whereDate('starts_on', '<=', $ends))
            // A cancelled one (last day before its first) covers nothing.
            ->get()->contains(fn (ResourceAssignment $a) => $a->ends_on === null || $a->ends_on->greaterThanOrEqualTo($a->starts_on));
    }
}
