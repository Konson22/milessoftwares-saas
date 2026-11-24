<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\AssetReports;
use App\Logbook\Entries;
use App\Logbook\Exceptions;
use App\Logbook\Fields;
use App\Logbook\FuelReports;
use App\Logbook\Investigations;
use App\Logbook\Issues;
use App\Logbook\Member;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Shift;
use App\Logbook\Models\Task;
use App\Logbook\Models\Track;
use App\Logbook\Reports;
use App\Logbook\ResourceCategories;
use App\Logbook\Tasks;
use App\Logbook\Timeline;
use App\Logbook\Tracks;
use App\Logbook\Trends;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Counts, numeric summaries and exports over the filtered entries the
 * member may read (never drafts). Exports carry the same filters and the
 * same limits as the page.
 */
class ReportController extends Controller
{
    public const PDF_LIMIT = 500;

    public function index(Request $request): Response
    {
        $filters = $request->validate([...Reports::rules(), 'view' => ['nullable', Rule::in(self::VIEWS)], 'page' => ['nullable', 'integer', 'min:1']]);
        $view = (string) ($filters['view'] ?? 'overview');
        unset($filters['view'], $filters['page']);
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->first() : null;

        // Only the open view is worked out (ADR-0029).
        return Inertia::render('reports/index', [
            'filters' => $filters,
            'view' => $view,
            'options' => self::filterOptions() + self::reportOptions(),
            'counts' => Reports::counts($filters),
            'issues' => $view === 'overview' ? Issues::report($filters) : null,
            'numbers' => $view === 'overview' && $sheet !== null ? Reports::numbers($sheet, $filters) : null,
            'sheet' => $sheet === null ? null : ['id' => $sheet->id, 'name' => $sheet->name, 'code' => $sheet->code],
            'table' => $view === 'table' ? self::table($filters, $sheet) : null,
            'timeline' => $view === 'timeline' ? Timeline::build($filters) : null,
            'trends' => $view === 'trends' && $sheet !== null ? Trends::forSheet($sheet, $filters) : null,
            'map' => $view === 'map' ? self::map($filters) : null,
            'activities' => $view === 'activities' ? self::activities($filters) : null,
            // ADR-0031: by asset, measurements per asset for the chosen sheet, scheduled compliance.
            'assets' => $view === 'assets' ? AssetReports::assets($filters) : null,
            'measurements' => $view === 'assets' && $sheet !== null ? AssetReports::measurements($sheet, $filters) : null,
            'compliance' => $view === 'compliance' ? AssetReports::compliance($filters) : null,
            'applied' => Reports::describe($filters),
            'pdfLimit' => self::PDF_LIMIT,
        ]);
    }

    /**
     * One entry in full, opened over the report (ADR-0029): answers with
     * out-of-range flags, files, revisions, review decisions, related
     * tasks and exceptions, location and route, audit history, and the
     * actions the viewer may take. Read through Access::entry, so a link
     * never opens more than the entry page would.
     */
    public function record(string $entry): Modal
    {
        $entry = Access::entry($entry);
        abort_if($entry->status === 'draft', 404);
        $entry->load(['logbook:id,name', 'sheet', 'version', 'creator:id,name', 'reviewer:id,name']);
        $files = $entry->attachments()->get();
        $answers = self::answers($entry->version->fields, $entry->values ?? [], $files);
        $resourceIds = [];
        foreach ($entry->version->fields as $field) {
            if ($field['type'] === 'resource' && is_string($entry->values[$field['name']] ?? null)) {
                $resourceIds[] = $entry->values[$field['name']];
            }
        }
        $member = Member::current();

        return Inertia::modal('reports/record', [
            'entry' => [
                ...self::entryRow($entry),
                'version' => $entry->version->version,
                'revision' => $entry->revision,
                'reviewer' => $entry->reviewer?->name,
                'reviewedAt' => self::time($entry->reviewed_at),
                'answers' => $answers,
                'location' => $entry->location,
                'track' => $entry->log_track_id !== null && Tracks::visible()->whereKey($entry->log_track_id)->exists() ? $entry->log_track_id : null,
                'logbookId' => $entry->logbook_id,
                // ADR-0030: the activity and the calculation kept at its last submission.
                'activityStartedAt' => self::time($entry->activity_started_at),
                'activityEndedAt' => self::time($entry->activity_ended_at),
                'shift' => $entry->shift?->label(),
                'calculation' => is_array($entry->calculation) && isset($entry->calculation['method'])
                    ? [...$entry->calculation, 'resource' => is_string($entry->calculation['resource_id'] ?? null) ? LogResource::query()->find($entry->calculation['resource_id'])?->label() : null]
                    : null,
            ],
            'resources' => LogResource::query()->whereIn('id', $resourceIds)->get()->map(fn (LogResource $r) => ['id' => $r->id, 'label' => $r->label()])->values(),
            'revisions' => $entry->revisions()->with('submitter:id,name')->get()->map(fn (EntryRevision $r) => [
                'revision' => $r->revision, 'submittedAt' => self::time($r->submitted_at), 'submittedBy' => $r->submitter->name, 'occurredAt' => self::time($r->occurred_at),
            ])->values(),
            'reviews' => $entry->reviews()->get()->map(fn (EntryReview $r) => [
                'id' => $r->id, 'revision' => $r->revision, 'decision' => $r->decision, 'comment' => $r->comment, 'reviewer' => $r->reviewer_name, 'decidedAt' => self::time($r->decided_at),
            ])->values(),
            'exceptions' => Exceptions::visible()->where('log_entry_id', $entry->id)->latest()->get()
                ->map(fn (LogException $x) => ['id' => $x->id, 'reference' => $x->reference, 'title' => $x->title, 'severity' => $x->severity, 'status' => $x->status, 'field' => $x->field_label])->values(),
            'tasks' => Tasks::visible()->where('log_entry_id', $entry->id)->with('assignee:id,name')->latest()->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'reference' => $t->reference, 'title' => $t->title, 'status' => $t->status, 'assignee' => $t->assignee->name, 'dueAt' => self::time($t->due_at)])->values(),
            'history' => Gate::allows('logbook.audit.view') || Access::managesLogbook($entry->logbook)
                ? AuditEvent::query()->where('subject_type', 'entry')->where('subject_id', $entry->id)->orderBy('created_at')->get()
                    ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'at' => self::time($e->created_at)])->values()
                : null,
            'members' => Gate::allows(Tasks::MANAGE) ? WorkspaceMember::options() : [],
            'investigations' => Investigations::canManage($member) ? Investigations::openOptions() : [],
            'can' => [
                'review' => $entry->status === 'submitted' && $entry->created_by !== $member->id
                    && Gate::allows('logbook.entries.review') && Access::reachesSheet($entry->sheet, 'review'),
                'reportException' => Gate::any(['logbook.entries.create', 'logbook.entries.review', Tasks::MANAGE]),
                'createTask' => Gate::allows(Tasks::MANAGE),
                'investigate' => Investigations::canManage($member),
            ],
        ])->baseRoute('reports.index');
    }

    public const VIEWS = ['overview', 'table', 'timeline', 'trends', 'map', 'activities', 'assets', 'compliance'];

    /**
     * The asset or compliance report as CSV or PDF (ADR-0031 §13), with
     * the period and filters at its head. Same rows as the page.
     */
    public function summaryExport(Request $request, string $report, string $format): StreamedResponse|HttpResponse
    {
        abort_unless(in_array($report, ['assets', 'compliance'], true) && in_array($format, ['csv', 'pdf'], true), 404);
        $filters = $request->validate(Reports::rules());
        $tz = Workspace::current()->timezone ?? 'UTC';
        [$title, $columns, $rows] = $report === 'assets' ? self::assetTable($filters) : self::complianceTable($filters);
        $applied = Reports::describe($filters);
        $name = 'logbook-'.$report.'-'.now($tz)->format('Y-m-d');

        if ($format === 'pdf') {
            return Pdf::loadView('logbook.summary-pdf', [
                'organization' => Workspace::current()?->display_name, 'title' => $title, 'applied' => $applied,
                'columns' => $columns, 'rows' => $rows, 'timezone' => $tz, 'printedBy' => $this->user()->name,
            ])->setPaper('a4', 'landscape')->download($name.'.pdf');
        }

        return response()->streamDownload(function () use ($columns, $rows, $applied, $title) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fwrite($out, "\xEF\xBB\xBF");
            // What the export holds: the report and its filters, then the table.
            fputcsv($out, [self::safe($title.' · '.implode(' · ', $applied))], escape: '');
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row), escape: '');
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<mixed>>}
     */
    private static function assetTable(array $filters): array
    {
        $report = AssetReports::assets($filters);

        return ['Assets', ['Asset', 'Category', 'Kind', 'Location', 'Status', 'Records', 'Last record', 'Readings out of range', 'Open exceptions', 'Expected checks', 'On time', 'Missed', 'Fuel estimated (L)'],
            array_map(fn (array $r) => [$r['label'], $r['type'], $r['kind'] === 'monitored_location' ? 'Monitored location' : 'Asset', $r['location'], $r['status'],
                $r['records'], $r['lastAt'], $r['outOfRange'], $r['openExceptions'], $r['expected'], $r['onTime'], $r['missed'], $r['fuelL']], $report['rows'])];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<string>, 2: list<list<mixed>>}
     */
    private static function complianceTable(array $filters): array
    {
        $report = AssetReports::compliance($filters);
        $line = fn (array $r, string $sheet, ?string $asset) => [$sheet, $asset ?? 'Whole sheet', $r['expected'], $r['onTime'], $r['late'], $r['missed'], $r['excused'], $r['awaitingReview'], $r['open'], $r['rate'] === null ? null : $r['rate'].'%'];

        return ['Scheduled compliance', ['Log sheet', 'Asset', 'Expected', 'Submitted on time', 'Submitted late', 'Missed', 'Excused', 'Awaiting review', 'Open', 'Completion'],
            [...array_map(fn (array $r) => $line($r, $r['sheet'], $r['resource']), $report['rows']), $line($report['totals'], 'Total', '')]];
    }

    /**
     * Activities and fuel (ADR-0030 §4.9): totals by resource, operator,
     * shift and month, and the activities themselves, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function activities(array $filters): array
    {
        $page = FuelReports::query($filters)->with(['sheet:id,name,code', 'creator:id,name', 'shift:id,name'])
            ->reorder()->orderByDesc('activity_started_at')->orderByDesc('occurred_at')->paginate(50)->withQueryString();
        [$resources, $exceptions] = FuelReports::lookups($page->items());

        return [
            'summary' => FuelReports::summary($filters),
            'rows' => $page->through(fn (Entry $e) => FuelReports::row($e, $resources, $exceptions)),
        ];
    }

    /** The filtered activities as CSV: one line per entry, its kept calculation. */
    public function activitiesCsv(Request $request): StreamedResponse
    {
        $filters = $request->validate(Reports::rules());
        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = FuelReports::query($filters)->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'shift:id,name']);

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Reference', 'Logbook', 'Log sheet', 'Resource', 'Operator', 'Shift', 'Started', 'Ended', 'Duration (h)', 'Quantity', 'Unit',
                'Rate', 'Rate unit', 'Estimated (L)', 'Fuel measured (L)', 'Difference from estimate (L)', 'Difference (%)', 'Refill (L)', 'Route (km)', 'Notes', 'Exceptions', 'Status'], escape: '');
            $query->reorder()->orderBy('activity_started_at')->orderBy('occurred_at')->orderBy('id')->chunk(500, function ($entries) use ($out, $tz) {
                [$resources, $exceptions] = FuelReports::lookups($entries);
                foreach ($entries as $e) {
                    /** @var Entry $e */
                    $row = FuelReports::row($e, $resources, $exceptions);
                    $rate = $e->calculation['rate'] ?? null;
                    $local = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->setTimezone($tz)->format('Y-m-d H:i');
                    fputcsv($out, array_map(self::safe(...), [
                        $row['reference'], $e->logbook->name, $row['sheet'], $row['resource'], $row['operator'], $row['shift'],
                        $local($row['startedAt']), $local($row['endedAt']), $row['durationH'], $row['quantity'], $row['unit'],
                        $rate['value'] ?? null, $rate['unit'] ?? null, $row['estimatedL'], $row['actualL'], $row['varianceL'], $row['variancePct'],
                        $row['refillL'], $row['gpsKm'], implode('; ', $row['flags']), $row['exceptions'], $row['status'],
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'logbook-activities-'.now($tz)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * People, items and places to filter by: only what this workspace has.
     *
     * @return array<string, mixed>
     */
    private static function reportOptions(): array
    {
        return [
            'people' => array_map(fn (array $o) => ['value' => $o['value'], 'label' => preg_replace('/ \\(.*\\)$/', '', $o['label'])], WorkspaceMember::options()),
            'resources' => array_values(LogResource::query()->orderBy('code')->get()->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->all()),
            'locations' => array_values(Location::query()->orderBy('name')->get(['id', 'name'])->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])->all()),
            'categories' => array_map(fn (array $c) => ['value' => $c['value'], 'label' => $c['label']], ResourceCategories::options()),
            'shifts' => array_values(Shift::query()->orderBy('starts_time')->get()->map(fn (Shift $s) => ['value' => $s->id, 'label' => $s->label().($s->active ? '' : ' (off)')])->all()),
        ];
    }

    /**
     * Entries as rows; with one sheet chosen, its fields as columns, each
     * value read with the entry's own version.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function table(array $filters, ?LogSheet $sheet): array
    {
        $columns = $sheet !== null
            ? array_values(array_filter(Reports::columns($sheet), fn (array $f) => $f['type'] !== 'file'))
            : [];
        $page = Reports::query($filters)->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields'])
            ->latest('occurred_at')->paginate(50)->withQueryString();

        return [
            'columns' => array_map(fn (array $f) => ['name' => $f['name'], 'label' => $f['label'], 'unit' => $f['unit'] ?? null, 'numeric' => in_array($f['type'], Fields::NUMERIC, true)], $columns),
            'rows' => $page->through(function (Entry $e) use ($columns) {
                $row = self::entryRow($e);
                if ($columns !== []) {
                    $people = Entries::people($e->version->fields, $e->values ?? []);
                    $deviations = array_column(Fields::deviations($e->version->fields, $e->values ?? []), null, 'name');
                    $byName = array_column($e->version->fields, null, 'name');
                    $row['cells'] = array_map(fn (array $c) => [
                        'text' => isset($byName[$c['name']]) ? Fields::text($byName[$c['name']], $e->values[$c['name']] ?? null, $people, []) : null,
                        'flag' => isset($deviations[$c['name']]) ? $deviations[$c['name']]['direction'] : null,
                    ], $columns);
                }

                return $row;
            }),
        ];
    }

    /**
     * Where entries were written (their own captured location) and the
     * routes recorded in the same scope. Nothing is drawn that was not
     * captured; sheets without GPS simply add nothing.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function map(array $filters): array
    {
        $points = Reports::query($filters)->whereNotNull('location')->with('sheet:id,code')->reorder()->latest('occurred_at')->limit(500)->get();
        $tz = Workspace::current()->timezone ?? 'UTC';
        $from = isset($filters['from']) ? CarbonImmutable::parse((string) $filters['from'], $tz)->startOfDay()->utc() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse((string) $filters['to'], $tz)->addDay()->startOfDay()->utc() : null;
        $tracks = Tracks::visible()->with(['user:id,name', 'sheet:id,code,name', 'resource:id,code,name'])
            ->when($filters['logbook'] ?? null, fn ($q, $id) => $q->where('logbook_id', $id))
            ->when($filters['sheet'] ?? null, fn ($q, $id) => $q->where('log_sheet_id', $id))
            ->when($filters['resource'] ?? null, fn ($q, $id) => $q->where('log_resource_id', $id))
            ->when($filters['person'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($from, fn ($q, $d) => $q->where('started_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('started_at', '<', $d))
            ->latest('started_at')->limit(50)->get();

        return [
            'entries' => $points->filter(fn (Entry $e) => isset($e->location['lat'], $e->location['lng']))->map(fn (Entry $e) => [
                'id' => $e->id, 'label' => ($e->reference ?? 'Entry').' · '.$e->sheet->code, 'at' => self::time($e->occurred_at),
                'lat' => (float) $e->location['lat'], 'lng' => (float) $e->location['lng'], 'href' => route('entries.show', $e->id, false),
            ])->values(),
            'tracks' => $tracks->map(fn (Track $t) => [
                'id' => $t->id, 'user' => $t->user->name, 'sheet' => $t->sheet->code.' · '.$t->sheet->name, 'resource' => $t->resource?->label(),
                'startedAt' => self::time($t->started_at), 'endedAt' => self::time($t->ended_at), 'distanceM' => $t->distance_m, 'status' => $t->status,
            ])->values(),
            'truncated' => $points->count() >= 500,
            'tiles' => ['url' => (string) config('logbook.map.tiles'), 'attribution' => (string) config('logbook.map.attribution')],
        ];
    }

    /**
     * The filtered entries as CSV. With one sheet chosen, one column per
     * field the sheet has ever had; otherwise a summary column.
     */
    public function csv(Request $request): StreamedResponse
    {
        $filters = $request->validate(Reports::rules());
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->firstOrFail() : null;
        $columns = $sheet !== null ? Reports::columns($sheet) : [];
        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = Reports::query($filters)->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'reviewer:id,name', 'version:id,version,fields', 'attachments:id,log_entry_id,name']);

        $name = 'logbook-entries-'.($sheet !== null ? strtolower($sheet->code).'-' : '').now($tz)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $columns, $sheet, $tz) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fwrite($out, "\xEF\xBB\xBF");
            $head = ['Reference', 'Logbook', 'Log sheet', 'Template version', 'Occurred at', 'Status', 'Review', 'Created by', 'Submitted at', 'Reviewed by', 'Reviewed at'];
            fputcsv($out, array_map(self::safe(...), $sheet !== null ? [...$head, ...array_column($columns, 'label')] : [...$head, 'Summary']), escape: '');

            $query->reorder()->orderBy('occurred_at')->orderBy('id')->chunk(500, function ($entries) use ($out, $columns, $sheet, $tz) {
                foreach ($entries as $e) {
                    /** @var Entry $e */
                    $row = [
                        $e->reference, $e->logbook->name, $e->sheet->name, $e->version->version,
                        $e->occurred_at->setTimezone($tz)->format('Y-m-d H:i'), $e->status,
                        $e->review_required ? ($e->status === 'reviewed' ? 'Reviewed' : 'Required') : 'Not required',
                        $e->creator->name, $e->submitted_at?->setTimezone($tz)->format('Y-m-d H:i'),
                        $e->reviewer?->name, $e->reviewed_at?->setTimezone($tz)->format('Y-m-d H:i'),
                    ];
                    if ($sheet !== null) {
                        $own = Fields::byName($e->version->fields);
                        $people = Entries::people($e->version->fields, $e->values);
                        $files = $e->attachments->pluck('name', 'id')->all();
                        foreach ($columns as $column) {
                            $field = $own[$column['name']] ?? $column;
                            $row[] = Fields::text($field, $e->values[$column['name']] ?? null, $people, $files);
                        }
                    } else {
                        $row[] = self::summary($e);
                    }
                    fputcsv($out, array_map(self::safe(...), $row), escape: '');
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The filtered entries as a printable PDF listing, up to PDF_LIMIT. */
    public function pdf(Request $request): HttpResponse
    {
        $filters = $request->validate(Reports::rules());
        $sheet = isset($filters['sheet']) ? Access::sheets()->whereKey((string) $filters['sheet'])->firstOrFail() : null;
        $query = Reports::query($filters);
        $total = (clone $query)->count();
        if ($total > self::PDF_LIMIT) {
            throw ValidationException::withMessages(['export' => "{$total} entries match. A PDF holds at most ".self::PDF_LIMIT.'; narrow the filters or export CSV.']);
        }
        $tz = Workspace::current()->timezone ?? 'UTC';
        $entries = $query->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields', 'attachments:id,log_entry_id,name'])
            ->reorder()->orderBy('occurred_at')->get();

        return Pdf::loadView('logbook.entries-pdf', [
            'organization' => Workspace::current()?->display_name,
            'sheet' => $sheet,
            'filters' => $filters,
            'applied' => Reports::describe($filters),
            'timezone' => $tz,
            'printedBy' => $this->user()->name,
            'rows' => $entries->map(fn (Entry $e) => [
                'entry' => $e,
                'answers' => self::answers($e->version->fields, $e->values, $e->attachments),
            ]),
        ])->setPaper('a4', 'landscape')->download('logbook-entries-'.now($tz)->format('Y-m-d').'.pdf');
    }

    /** A cell a spreadsheet will not run as a formula (same rule as M&E exports). */
    public static function safe(mixed $value): string
    {
        $value = $value === null ? '' : (is_scalar($value) ? (string) $value : (string) json_encode($value));

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
