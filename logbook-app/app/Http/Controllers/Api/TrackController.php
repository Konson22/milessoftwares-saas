<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Logbook\Models\Track;
use App\Logbook\Tracks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Routes from the phone (ADR-0028). The route's details and its points go
 * separately, so a long route resumes where an upload stopped. Every call
 * is safe to repeat: the route by its UUID, points and events by the
 * phone's sequence number.
 *
 * Refusals the phone must not retry answer 409 with a code: sheet_closed,
 * routes_off, resource_unknown, resource_required, track_taken.
 */
class TrackController extends Controller
{
    /** The person's own routes, newest first: the phone's history. */
    public function index(): JsonResponse
    {
        $tracks = Track::query()->where('user_id', $this->user()->id)->with(['sheet:id,name,code', 'resource:id,code,name'])
            ->latest('started_at')->limit(50)->get();

        return response()->json(['tracks' => $tracks->map(fn (Track $t) => self::row($t))->values()->all()]);
    }

    /** Creates the route, or moves its status on (pause, resume, end). */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'sheet_id' => ['required', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'status' => ['required', Rule::in(Track::STATUSES)],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        [$track, $code, $message] = Tracks::upsert($this->user(), $data);
        if ($track === null) {
            return response()->json(['code' => $code, 'message' => $message], 409);
        }

        return response()->json(self::row($track->load(['sheet:id,name,code', 'resource:id,code,name'])));
    }

    /** A batch of points and events, in the phone's order. */
    public function points(Request $request, string $track): JsonResponse
    {
        $track = Track::query()->whereKey($track)->where('user_id', $this->user()->id)->first();
        if ($track === null) {
            return response()->json(['code' => 'track_unknown', 'message' => 'Send the route before its points.'], 409);
        }

        $data = $request->validate([
            'points' => ['present', 'array', 'max:'.Tracks::BATCH],
            'points.*.seq' => ['required', 'integer', 'min:1'],
            'points.*.lat' => ['required'],
            'points.*.lng' => ['required'],
            'points.*.captured_at' => ['required', 'string'],
            'events' => ['present', 'array', 'max:'.Tracks::BATCH],
            'events.*.seq' => ['required', 'integer', 'min:1'],
            'events.*.kind' => ['required', 'string'],
            'events.*.at' => ['required', 'string'],
        ]);

        $result = Tracks::append($track, $data['points'], $data['events']);
        $track->refresh();

        return response()->json([
            ...$result,
            'track' => self::row($track->load(['sheet:id,name,code', 'resource:id,code,name'])),
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(Track $t): array
    {
        return [
            'id' => $t->id,
            'status' => $t->status,
            'sheet' => ['id' => $t->log_sheet_id, 'name' => $t->sheet->name, 'code' => $t->sheet->code],
            'resource' => $t->resource !== null ? ['id' => $t->resource->id, 'label' => $t->resource->label()] : null,
            'startedAt' => $t->started_at->toIso8601String(),
            'endedAt' => $t->ended_at?->toIso8601String(),
            'distanceM' => $t->distance_m,
            'movingSeconds' => $t->moving_seconds,
            'points' => $t->points_count,
            'gaps' => $t->gaps_count,
            'lastSeq' => $t->last_seq,
        ];
    }
}
