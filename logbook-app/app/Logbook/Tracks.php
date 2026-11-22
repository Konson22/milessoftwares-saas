<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Task;
use App\Logbook\Models\Track;
use App\Logbook\Models\TrackEvent;
use App\Logbook\Models\TrackPoint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Optional GPS on log sheets (ADR-0028).
 *
 * - A sheet version takes no location (default), the entry's location, or
 *   a route. Recording is always started by the person on their phone;
 *   setting a sheet to routes starts nothing.
 * - A route is created and filled by the phone, keyed by its own ids: the
 *   track by UUID, each point and event by the phone's sequence number. A
 *   resent request adds nothing.
 * - Anyone sees their own routes. Other people's need logbook.tracks.view
 *   and the logbook (restricted logbooks included), or logbook.manage.
 */
final class Tracks
{
    public const VIEW = 'logbook.tracks.view';

    public const MODES = ['entry', 'route'];

    /** Points and events taken in one request. */
    public const BATCH = 500;

    /** A phone clock this far ahead of the server is not believed. */
    public const SKEW_SECONDS = 600;

    // ---- Sheet settings ----

    /**
     * The location settings for a draft, checked. Null or mode `off`: none.
     *
     * @return array{mode: string, required: bool, resource_required: bool, accuracy_m: int|null}|null
     */
    public static function settingsInput(mixed $input): ?array
    {
        if ($input === null || $input === [] || (is_array($input) && ($input['mode'] ?? 'off') === 'off')) {
            return null;
        }
        if (! is_array($input) || ! in_array($input['mode'] ?? null, self::MODES, true)) {
            throw ValidationException::withMessages(['location.mode' => 'Choose no location, the entry location, or a route.']);
        }
        $accuracy = $input['accuracy_m'] ?? null;
        if ($accuracy !== null && $accuracy !== '' && (! is_numeric($accuracy) || (int) $accuracy < 5 || (int) $accuracy > 1000)) {
            throw ValidationException::withMessages(['location.accuracy_m' => 'Accuracy guidance is between 5 and 1000 metres.']);
        }

        return [
            'mode' => $input['mode'],
            'required' => (bool) ($input['required'] ?? false),
            'resource_required' => $input['mode'] === 'route' && (bool) ($input['resource_required'] ?? false),
            'accuracy_m' => $accuracy === null || $accuracy === '' ? null : (int) $accuracy,
        ];
    }

    /** @return array{mode: string, required: bool, resource_required: bool, accuracy_m: int|null}|null */
    public static function settings(?LogSheetVersion $version): ?array
    {
        $location = $version?->location;

        return is_array($location) && in_array($location['mode'], self::MODES, true) ? $location : null;
    }

    // ---- Who sees what ----

    /** @return Builder<Track> */
    public static function visible(?Member $member = null): Builder
    {
        $member ??= Member::current();
        // A whole-workspace manager sees every route; a department manager
        // those on their logbooks (Access::limit), like others with tracks.view.
        if (Access::managesEverything($member)) {
            return Track::query();
        }

        return Track::query()->where(function (Builder $q) use ($member) {
            $q->where('user_id', $member->id);
            if ($member->can(self::VIEW) || Access::manages($member)) {
                $q->orWhere(fn (Builder $others) => Access::limit($others, ['view', 'review'], member: $member));
            }
        });
    }

    public static function find(string $id): Track
    {
        return self::visible()->findOrFail($id);
    }

    // ---- From the phone ----

    /**
     * Creates the route the first time, then moves its status on. Ended is
     * final. Returns null and a reason when it cannot be taken.
     *
     * @param  array<string, mixed>  $data  validated: id, sheet_id, resource_id?, task_id?, status, started_at, ended_at?, device?
     * @return array{0: Track|null, 1: string|null, 2: string|null}
     */
    public static function upsert(User $by, array $data): array
    {
        $existing = Track::acrossTenants()->whereKey($data['id'])->first();
        $tenant = app(CurrentTenant::class)->require()->id;
        if ($existing !== null && ($existing->organization_id !== $tenant || $existing->user_id !== $by->id)) {
            return [null, 'track_taken', 'This route id belongs to another recording.'];
        }

        return DB::transaction(function () use ($by, $data, $existing) {
            $track = $existing !== null ? Track::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail() : null;

            if ($track === null) {
                $sheet = Access::writableSheets()->whereKey($data['sheet_id'])->with('publishedVersion')->first();
                if (! $sheet instanceof LogSheet) {
                    return [null, 'sheet_closed', 'This log sheet no longer takes entries from you.'];
                }
                $settings = self::settings($sheet->publishedVersion);
                if (($settings['mode'] ?? null) !== 'route') {
                    return [null, 'routes_off', 'This log sheet does not record routes.'];
                }
                $resourceId = $data['resource_id'] ?? null;
                if ($resourceId !== null && ! LogResource::query()->whereKey($resourceId)->where('active', true)->exists()) {
                    return [null, 'resource_unknown', 'This vehicle or resource is not available.'];
                }
                if ($resourceId === null && $settings['resource_required']) {
                    return [null, 'resource_required', 'Choose the vehicle or resource for this route.'];
                }
                $taskId = $data['task_id'] ?? null;
                if ($taskId !== null && ! Task::query()->whereKey($taskId)->where('assignee_id', $by->id)->exists()) {
                    $taskId = null;
                }

                $track = Track::query()->create([
                    'id' => $data['id'],
                    'user_id' => $by->id,
                    'logbook_id' => $sheet->logbook_id,
                    'log_sheet_id' => $sheet->id,
                    'log_resource_id' => $resourceId,
                    'task_id' => $taskId,
                    'source' => 'phone',
                    'status' => 'recording',
                    'started_at' => self::clock($data['started_at']),
                    'device' => isset($data['device']) ? mb_substr((string) $data['device'], 0, 120) : null,
                ]);
                AuditTrail::record('track.started', $track, $by, [
                    'resource' => $track->resource?->label(),
                ]);
            }

            if ($track->status !== 'ended' && in_array($data['status'], Track::STATUSES, true) && $data['status'] !== $track->status) {
                $track->status = $data['status'];
                if ($data['status'] === 'ended') {
                    $track->ended_at = self::clock($data['ended_at'] ?? now()->toIso8601String());
                    AuditTrail::record('track.ended', $track, $by);
                }
                $track->save();
            }

            return [$track, null, null];
        });
    }

    /**
     * Stores a batch of points and events, ignoring any already stored,
     * then works the summary out again. Returns the highest sequence now
     * held and the points refused (kept on the phone for review).
     *
     * @param  list<array<string, mixed>>  $points
     * @param  list<array<string, mixed>>  $events
     * @return array{acknowledged: int, refused: list<int>}
     */
    public static function append(Track $track, array $points, array $events): array
    {
        $now = CarbonImmutable::now();
        $latest = $now->addSeconds(self::SKEW_SECONDS);
        $refused = [];
        $pointRows = [];
        foreach ($points as $p) {
            $at = self::time($p['captured_at'] ?? null);
            $lat = $p['lat'] ?? null;
            $lng = $p['lng'] ?? null;
            if ($at === null || $at->gt($latest) || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                $refused[] = (int) $p['seq'];

                continue;
            }
            $pointRows[] = [
                'organization_id' => $track->organization_id,
                'log_track_id' => $track->id,
                'seq' => (int) $p['seq'],
                'latitude' => round((float) $lat, 7),
                'longitude' => round((float) $lng, 7),
                'accuracy_m' => self::number($p['accuracy_m'] ?? null),
                'speed_mps' => self::number($p['speed_mps'] ?? null),
                'altitude_m' => self::number($p['altitude_m'] ?? null),
                'captured_at' => $at,
                'received_at' => $now,
            ];
        }
        $eventRows = [];
        foreach ($events as $e) {
            $at = self::time($e['at'] ?? null);
            if ($at === null || ! in_array($e['kind'] ?? null, TrackEvent::KINDS, true)) {
                $refused[] = (int) $e['seq'];

                continue;
            }
            $eventRows[] = [
                'organization_id' => $track->organization_id,
                'log_track_id' => $track->id,
                'seq' => (int) $e['seq'],
                'kind' => $e['kind'],
                'at' => $at->gt($latest) ? $now : $at,
                'note' => isset($e['note']) ? mb_substr((string) $e['note'], 0, 200) : null,
                'received_at' => $now,
            ];
        }

        DB::transaction(function () use ($track, $pointRows, $eventRows) {
            // Unique (track, seq): a resent batch, or one that crossed a lost reply, adds nothing.
            foreach (array_chunk($pointRows, 200) as $chunk) {
                TrackPoint::query()->insertOrIgnore($chunk);
            }
            foreach (array_chunk($eventRows, 200) as $chunk) {
                TrackEvent::query()->insertOrIgnore($chunk);
            }
            self::summarize(Track::query()->whereKey($track->id)->lockForUpdate()->firstOrFail());
        });

        $seqs = array_merge(array_column($points, 'seq'), array_column($events, 'seq'));

        return ['acknowledged' => $seqs === [] ? 0 : (int) max($seqs), 'refused' => $refused];
    }

    /** The summary on the track, from everything stored. */
    public static function summarize(Track $track): void
    {
        $result = self::route($track);
        $last = TrackPoint::query()->where('log_track_id', $track->id)->max('captured_at');
        $track->forceFill([
            'distance_m' => $result['distance_m'],
            'moving_seconds' => $result['moving_seconds'],
            'points_count' => TrackPoint::query()->where('log_track_id', $track->id)->count(),
            'rejected_points' => $result['rejected'],
            'gaps_count' => $result['gaps_count'],
            'gap_seconds' => $result['gap_seconds'],
            'bounds' => $result['bounds'],
            'last_seq' => (int) max(
                (int) TrackPoint::query()->where('log_track_id', $track->id)->max('seq'),
                (int) TrackEvent::query()->where('log_track_id', $track->id)->max('seq'),
            ),
            'last_point_at' => $last !== null ? CarbonImmutable::parse($last) : null,
        ])->save();
    }

    /** @return array<string, mixed> Route::summarize() over the stored points and events */
    public static function route(Track $track): array
    {
        $points = TrackPoint::query()->where('log_track_id', $track->id)->orderBy('seq')
            ->get(['seq', 'latitude', 'longitude', 'accuracy_m', 'captured_at'])
            ->map(fn (TrackPoint $p) => [
                'seq' => $p->seq,
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'accuracy_m' => $p->accuracy_m,
                't' => $p->captured_at->getTimestamp(),
            ])->all();
        $events = TrackEvent::query()->where('log_track_id', $track->id)->orderBy('seq')->get(['seq', 'kind', 'at'])
            ->map(fn (TrackEvent $e) => ['seq' => $e->seq, 'kind' => $e->kind, 't' => $e->at->getTimestamp()])->all();

        return Route::summarize(array_values($points), array_values($events));
    }

    /**
     * Removes a route and its points. Entries written on it keep their own
     * location; only the link goes.
     */
    public static function delete(Track $track, User $by): void
    {
        DB::transaction(function () use ($track, $by) {
            AuditTrail::record('track.deleted', $track, $by, [
                'started_at' => $track->started_at->toIso8601String(),
                'points' => $track->points_count,
            ]);
            Entry::query()->where('log_track_id', $track->id)->update(['log_track_id' => null]);
            TrackPoint::query()->where('log_track_id', $track->id)->delete();
            TrackEvent::query()->where('log_track_id', $track->id)->delete();
            $track->delete();
        });
    }

    /**
     * Retention (config logbook.tracks.retention_days): routes ended longer
     * ago than that are removed, a few at a time.
     */
    public static function prune(CarbonImmutable $now, int $limit = 20): int
    {
        $days = config('logbook.tracks.retention_days');
        if (! is_numeric($days) || (int) $days <= 0) {
            return 0;
        }
        $old = Track::query()->where('status', 'ended')->where('ended_at', '<', $now->subDays((int) $days))->limit($limit)->get();
        foreach ($old as $track) {
            DB::transaction(function () use ($track) {
                AuditTrail::record('track.expired', $track, null, ['retention_days' => (int) config('logbook.tracks.retention_days')]);
                Entry::query()->where('log_track_id', $track->id)->update(['log_track_id' => null]);
                TrackPoint::query()->where('log_track_id', $track->id)->delete();
                TrackEvent::query()->where('log_track_id', $track->id)->delete();
                $track->delete();
            });
        }

        return $old->count();
    }

    // ---- On entries ----

    /**
     * The location and route an entry may keep, for its version's settings.
     * A sheet without GPS keeps none, whatever was sent.
     *
     * @param  array<string, mixed>|null  $location
     * @return array{location: array<string, mixed>|null, log_track_id: string|null}
     */
    public static function forEntry(LogSheetVersion $version, User $by, ?array $location, ?string $trackId, string $source): array
    {
        $settings = self::settings($version);
        if ($settings === null) {
            return ['location' => null, 'log_track_id' => null];
        }

        $place = null;
        if ($location !== null) {
            $at = self::time($location['captured_at'] ?? null);
            $lat = $location['lat'] ?? null;
            $lng = $location['lng'] ?? null;
            if ($at === null || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                throw ValidationException::withMessages(['location' => 'The location taken is not valid.']);
            }
            $place = [
                'lat' => round((float) $lat, 7),
                'lng' => round((float) $lng, 7),
                'accuracy_m' => self::number($location['accuracy_m'] ?? null),
                'captured_at' => $at->toIso8601String(),
                'received_at' => now()->toIso8601String(),
                'source' => $source,
            ];
        }

        $track = null;
        if ($settings['mode'] === 'route' && $trackId !== null) {
            // Only the person's own route, in the same logbook: a vehicle's movement is never put on someone else.
            $track = Track::query()->whereKey($trackId)->where('user_id', $by->id)->where('logbook_id', $version->sheet->logbook_id)->value('id');
        }

        return ['location' => $place, 'log_track_id' => $track];
    }

    /** At submission: a sheet that requires a location gets one. */
    public static function checkRequired(Entry $entry): void
    {
        $settings = self::settings($entry->version);
        if ($settings === null || ! $settings['required']) {
            return;
        }
        if ($entry->location === null && ($settings['mode'] !== 'route' || $entry->log_track_id === null)) {
            throw ValidationException::withMessages(['location' => 'This log sheet needs the location where the entry was made. Turn on location and take it again.']);
        }
    }

    // ---- Helpers ----

    private static function clock(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value);
        $latest = CarbonImmutable::now()->addSeconds(self::SKEW_SECONDS);

        return $at->gt($latest) ? CarbonImmutable::now() : $at;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 2) : null;
    }
}
