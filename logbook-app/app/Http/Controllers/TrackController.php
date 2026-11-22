<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\AuditTrail;
use App\Logbook\Member;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\Track;
use App\Logbook\Models\TrackEvent;
use App\Logbook\Models\TrackPoint;
use App\Logbook\Tracks;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recorded routes on the web (ADR-0028): the list with filters, one route
 * on a map with its entries and gaps, a GPX export, and removal. Only the
 * points as recorded are drawn; nothing is routed along roads.
 */
class TrackController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'uuid'],
            'resource' => ['nullable', 'uuid'],
            'logbook' => ['nullable', 'uuid'],
            'sheet' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Track::STATUSES)],
            'track' => ['nullable', 'uuid'],
        ]);
        $member = Member::current();
        $seesOthers = $member->can(Tracks::VIEW) || Access::manages($member);

        $tracks = Tracks::visible()
            ->when($data['from'] ?? null, fn (Builder $q, $v) => $q->where('started_at', '>=', $v))
            ->when($data['to'] ?? null, fn (Builder $q, $v) => $q->where('started_at', '<', Carbon::parse($v)->addDay()->toDateString()))
            ->when($data['user'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($data['resource'] ?? null, fn (Builder $q, $v) => $q->where('log_resource_id', $v))
            ->when($data['logbook'] ?? null, fn (Builder $q, $v) => $q->where('logbook_id', $v))
            ->when($data['sheet'] ?? null, fn (Builder $q, $v) => $q->where('log_sheet_id', $v))
            ->when($data['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->with(['user:id,name', 'sheet:id,name,code', 'resource:id,code,name', 'logbook:id,name'])
            ->latest('started_at');

        $page = $tracks->paginate(25)->withQueryString();

        // The trip shown on the map beside the list: the one picked, else the newest listed.
        $picked = $data['track'] ?? null;
        $chosen = $picked !== null ? $page->getCollection()->firstWhere('id', $picked) : null;
        $chosen ??= $picked !== null ? Tracks::visible()->whereKey($picked)->with(['user:id,name', 'sheet:id,name,code', 'resource:id,code,name', 'logbook:id,name'])->first() : null;
        $chosen ??= $page->getCollection()->first();

        return Inertia::render('tracks/index', [
            'selected' => $chosen !== null ? self::row($chosen) + [
                'route' => self::mapRoute($chosen),
                'entries' => self::routeEntries($chosen),
            ] : null,
            'tiles' => self::tiles(),
            'filters' => [
                'from' => $data['from'] ?? '', 'to' => $data['to'] ?? '', 'user' => $data['user'] ?? '', 'resource' => $data['resource'] ?? '',
                'logbook' => $data['logbook'] ?? '', 'sheet' => $data['sheet'] ?? '', 'status' => $data['status'] ?? '',
            ],
            'tracks' => $page->through(fn (Track $t) => self::row($t)),
            'seesOthers' => $seesOthers,
            'options' => [
                ...self::filterOptions(),
                'members' => $seesOthers ? WorkspaceMember::options() : [],
                'resources' => array_values(LogResource::query()->orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->all()),
            ],
        ]);
    }

    public function show(string $track): Response
    {
        $track = Tracks::find($track);
        $track->load(['user:id,name', 'sheet:id,name,code', 'resource:id,code,name', 'logbook:id,name', 'task:id,reference,title']);
        $member = Member::current();

        $entries = self::routeEntries($track);

        return Inertia::render('tracks/show', [
            'track' => self::row($track) + [
                'task' => $track->task !== null ? ['id' => $track->task->id, 'label' => $track->task->reference.' · '.$track->task->title] : null,
                'device' => $track->device,
                'lastPointAt' => self::time($track->last_point_at),
                'gapSeconds' => $track->gap_seconds,
                'rejected' => $track->rejected_points,
            ],
            'route' => self::mapRoute($track),
            'events' => TrackEvent::query()->where('log_track_id', $track->id)->orderBy('seq')->get(['kind', 'at', 'note'])
                ->map(fn (TrackEvent $e) => ['kind' => $e->kind, 'at' => self::time($e->at), 'note' => $e->note])->values(),
            'entries' => $entries,
            'can' => ['delete' => Access::managesEverything($member), 'export' => true],
            'tiles' => self::tiles(),
        ]);
    }

    /** The recorded points as GPX, for whoever may see the route. Audited. */
    public function export(string $track): StreamedResponse
    {
        $track = Tracks::find($track);
        $track->load(['user:id,name', 'sheet:id,name,code']);
        AuditTrail::record('track.exported', $track, $this->user(), ['format' => 'gpx', 'points' => $track->points_count]);
        $name = 'route-'.$track->started_at->format('Ymd-His').'.gpx';

        return response()->streamDownload(function () use ($track) {
            $esc = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<gpx version="1.1" creator="Miles Logbook" xmlns="http://www.topografix.com/GPX/1/1">'."\n";
            echo '<metadata><name>'.$esc($track->sheet->code.' · '.$track->user->name.' · '.$track->started_at->toIso8601String()).'</name></metadata>'."\n";
            echo "<trk><trkseg>\n";
            TrackPoint::query()->where('log_track_id', $track->id)->orderBy('seq')->chunk(1000, function ($points) {
                foreach ($points as $p) {
                    /** @var TrackPoint $p */
                    echo '<trkpt lat="'.$p->latitude.'" lon="'.$p->longitude.'">'
                        .($p->altitude_m !== null ? '<ele>'.$p->altitude_m.'</ele>' : '')
                        .'<time>'.$p->captured_at->utc()->format('Y-m-d\TH:i:s\Z').'</time>'
                        .($p->accuracy_m !== null ? '<hdop>'.round($p->accuracy_m / 5, 1).'</hdop>' : '')
                        ."</trkpt>\n";
                }
            });
            echo "</trkseg></trk>\n</gpx>\n";
        }, $name, ['Content-Type' => 'application/gpx+xml', 'Cache-Control' => 'no-store, private']);
    }

    public function destroy(string $track): RedirectResponse
    {
        abort_unless(Access::managesEverything(), 403);
        $track = Tracks::find($track);
        $track->load('user:id,name');
        Tracks::delete($track, $this->user());

        return to_route('tracks.index')->with('success', 'Route removed. Entries written on it keep their own location.');
    }

    /** Phase 2 and 3 of ADR-0028: described, nothing built. */
    public function comingSoon(string $page): Response
    {
        abort_unless(in_array($page, ['gps-devices', 'fleet'], true), 404);

        return Inertia::render('integrations/coming-soon', ['page' => $page]);
    }

    /** @return array{segments: mixed, gaps: mixed, bounds: mixed} */
    private static function mapRoute(Track $track): array
    {
        $route = Tracks::route($track);

        return ['segments' => $route['segments'], 'gaps' => $route['gaps'], 'bounds' => $route['bounds']];
    }

    /**
     * Entries on the route the viewer may read: drawn where they were written.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function routeEntries(Track $track)
    {
        return Access::entries()->where('log_track_id', $track->id)->with('sheet:id,name,code')->orderBy('occurred_at')->get()
            ->map(fn (Entry $e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'status' => $e->status,
                'sheet' => $e->sheet->code.' · '.$e->sheet->name,
                'occurredAt' => self::time($e->occurred_at),
                'location' => $e->location !== null ? ['lat' => $e->location['lat'], 'lng' => $e->location['lng'], 'accuracy' => $e->location['accuracy_m']] : null,
            ])->values();
    }

    /** @return array{url: string, attribution: string} */
    private static function tiles(): array
    {
        return [
            'url' => (string) config('logbook.map.tiles'),
            'attribution' => (string) config('logbook.map.attribution'),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(Track $t): array
    {
        $user = $t->user;

        return [
            'id' => $t->id,
            'status' => $t->status,
            'source' => $t->source,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'sheet' => ['id' => $t->log_sheet_id, 'label' => $t->sheet->code.' · '.$t->sheet->name],
            'logbook' => $t->logbook->name,
            'resource' => $t->resource !== null ? ['id' => $t->resource->id, 'label' => $t->resource->label()] : null,
            'startedAt' => self::time($t->started_at),
            'endedAt' => self::time($t->ended_at),
            'distanceM' => $t->distance_m,
            'movingSeconds' => $t->moving_seconds,
            'points' => $t->points_count,
            'gaps' => $t->gaps_count,
        ];
    }
}
