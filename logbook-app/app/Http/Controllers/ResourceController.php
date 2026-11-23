<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\AssetTemplates;
use App\Logbook\AuditTrail;
use App\Logbook\Exceptions;
use App\Logbook\Fields;
use App\Logbook\Fuel;
use App\Logbook\Investigations;
use App\Logbook\Models\AssetTemplate;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\Investigation;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\MeterEvent;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Recording;
use App\Logbook\Schedules;
use App\Logbook\SheetAssets;
use App\Logbook\Sheets;
use App\Logbook\StarterTemplates;
use App\Logbook\Tasks;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Resources and locations (ADR-0026 §2.10), registered as assets from
 * templates and linked to the log sheets that record them (ADR-0031).
 * Everyone in Logbook reads them; managers (logbook.manage) register,
 * change, link and retire them. Never deleted: retired instead, so every
 * entry keeps naming the same asset.
 */
class ResourceController extends Controller
{
    public function index(): Response
    {
        $locations = Location::query()->with('parent.parent')->orderBy('name')->get();
        // The rate in force now, per resource (ADR-0030).
        $now = CarbonImmutable::now();
        $rates = FuelRate::query()->where('effective_from', '<=', $now)
            ->orderBy('effective_from')->orderBy('created_at')->orderBy('id')->get()
            ->keyBy('log_resource_id');
        $sheets = DB::table('log_sheet_resources')->where('organization_id', app(CurrentTenant::class)->require()->id)
            ->selectRaw('log_resource_id, count(*) as n')->groupBy('log_resource_id')->pluck('n', 'log_resource_id');

        return Inertia::render('resources/index', [
            'resources' => LogResource::query()->with(['location.parent', 'responsible:id,name'])
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END")->orderBy('code')->get()
                ->map(fn (LogResource $r) => [
                    'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'kind' => $r->kind, 'status' => $r->status,
                    'description' => $r->description, 'locationId' => $r->location_id, 'location' => $r->location?->path(), 'active' => $r->active,
                    'responsible' => $r->responsible?->name, 'template' => $r->template['name'] ?? null, 'sheets' => (int) ($sheets[$r->id] ?? 0),
                    'fuelRate' => ($rate = $rates->get($r->id)) !== null ? (float) $rate->rate.' '.$rate->unit() : null,
                ])->values(),
            'locations' => $locations->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'path' => $l->path(), 'parentId' => $l->parent_id, 'active' => $l->active])->values(),
            'types' => LogResource::query()->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /** Registering an asset (ADR-0031 §5): a template first, then its details. */
    public function create(Request $request): Response
    {
        return $this->form(null, (string) $request->query('template', ''));
    }

    public function edit(string $resource): Response
    {
        return $this->form(LogResource::query()->findOrFail($resource), '');
    }

    private function form(?LogResource $resource, string $template): Response
    {
        return Inertia::render('resources/form', [
            'resource' => $resource === null ? null : [
                'id' => $resource->id, 'code' => $resource->code, 'name' => $resource->name, 'type' => $resource->type, 'kind' => $resource->kind,
                'status' => $resource->status, 'description' => $resource->description, 'locationId' => $resource->location_id,
                'manufacturer' => $resource->manufacturer, 'model' => $resource->model, 'serialNumber' => $resource->serial_number,
                'responsibleId' => $resource->responsible_id,
                'metadata' => collect($resource->metadata ?? [])->map(fn ($m, $name) => ['name' => $name, ...$m])->values(),
                'template' => $resource->template === null ? null : ['name' => $resource->template['name'] ?? '', 'version' => $resource->template['version'] ?? 1],
            ],
            'templates' => AssetTemplates::all(),
            'chosen' => $template,
            'locations' => Location::query()->with('parent.parent')->where('active', true)->orderBy('name')->get()
                ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->path()])->values(),
            'members' => WorkspaceMember::options(),
            'types' => LogResource::query()->distinct()->orderBy('type')->pluck('type'),
            'now' => CarbonImmutable::now(Workspace::current()->timezone ?? 'UTC')->format('Y-m-d\TH:i'),
        ]);
    }

    /**
     * One resource with its fuel rates and meter events (ADR-0030 §4.6,
     * §4.8). Everyone may read them; managers add to them.
     */
    public function show(string $resource): Response
    {
        $resource = LogResource::query()->with(['location.parent', 'responsible:id,name'])->findOrFail($resource);
        $tz = Workspace::current()->timezone ?? 'UTC';
        $local = fn ($t) => $t?->setTimezone($tz)->format('Y-m-d H:i');
        $rates = FuelRate::query()->where('log_resource_id', $resource->id)->with('creator:id,name')
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')->get();
        $current = Fuel::rateAt($resource->id, CarbonImmutable::now());

        return Inertia::render('resources/show', [
            'resource' => [
                'id' => $resource->id, 'code' => $resource->code, 'name' => $resource->name, 'type' => $resource->type,
                'description' => $resource->description, 'location' => $resource->location?->path(), 'active' => $resource->active,
                'kind' => $resource->kind, 'status' => $resource->status, 'manufacturer' => $resource->manufacturer, 'model' => $resource->model,
                'serialNumber' => $resource->serial_number, 'responsible' => $resource->responsible?->name,
                'metadata' => array_values($resource->metadata ?? []),
                'template' => $resource->template === null ? null : ['name' => $resource->template['name'] ?? '', 'version' => $resource->template['version'] ?? 1],
            ],
            ...$this->operations($resource, $local),
            'currentRate' => $current?->id,
            'rates' => $rates->map(fn (FuelRate $r) => [
                'id' => $r->id, 'fuelType' => $r->fuel_type, 'method' => $r->method, 'rate' => (float) $r->rate, 'unit' => $r->unit(),
                'effectiveFrom' => $local($r->effective_from), 'note' => $r->note, 'by' => $r->creator->name, 'at' => $local($r->created_at),
                'upcoming' => $r->effective_from->isFuture(),
            ])->values(),
            'meterEvents' => MeterEvent::query()->where('log_resource_id', $resource->id)->with('creator:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('created_at')->get()
                ->map(fn (MeterEvent $e) => [
                    'id' => $e->id, 'measure' => $e->measure, 'kind' => $e->kind, 'reading' => (float) $e->reading,
                    'occurredAt' => $local($e->occurred_at), 'reason' => $e->reason, 'by' => $e->creator->name,
                ])->values(),
            'history' => AuditEvent::query()->where('subject_type', 'resource')->where('subject_id', $resource->id)
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'at' => $local($e->created_at)])->values(),
            'labels' => Fuel::LABELS,
            'now' => CarbonImmutable::now($tz)->format('Y-m-d\TH:i'),
            'canManage' => Gate::allows('logbook.manage'),
        ]);
    }

    public function storeRate(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $data = $request->validate([
            'fuel_type' => ['required', 'string'], 'method' => ['required', 'string'], 'rate' => ['required'],
            'effective_from' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $rate = Fuel::addRate($resource, $data, $this->user());

        return back()->with('success', "Fuel rate {$rate->rate} {$rate->unit()} added.");
    }

    public function storeMeterEvent(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $data = $request->validate([
            'measure' => ['required', 'string'], 'kind' => ['required', 'string'], 'reading' => ['required'],
            'occurred_at' => ['required', 'string'], 'reason' => ['required', 'string', 'max:500'],
        ]);
        Fuel::addMeterEvent($resource, $data, $this->user());

        return back()->with('success', 'Meter event recorded.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->resourceInput($request);
        $template = AssetTemplates::find($request->input('template'));
        $fuel = $this->fuelInput($request);
        $resource = DB::transaction(function () use ($data, $template, $fuel) {
            $resource = LogResource::query()->create([...$data, 'template' => $template !== null ? AssetTemplates::snapshot($template) : null]);
            AuditTrail::record('resource.created', $resource, $this->user(), ['template' => $template['name'] ?? null, 'kind' => $resource->kind]);
            if ($fuel !== null) {
                Fuel::addRate($resource, $fuel, $this->user());
            }

            return $resource;
        });

        return to_route('resources.show', $resource->id)->with('success', "{$resource->label()} registered. Link it to the log sheets that record it.");
    }

    public function update(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $data = $this->resourceInput($request, $resource);
        DB::transaction(function () use ($data, $resource) {
            $tracked = ['code', 'name', 'type', 'kind', 'status', 'location_id', 'manufacturer', 'model', 'serial_number', 'responsible_id'];
            $before = $resource->only($tracked);
            $resource->update($data);
            $after = $resource->only($tracked);
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            $action = $resource->wasChanged('status') ? 'resource.'.($resource->status === 'active' ? 'restored' : $resource->status) : 'resource.updated';
            AuditTrail::record($action, $resource, $this->user(), [
                'from' => array_intersect_key($before, array_flip($changed)), 'to' => array_intersect_key($after, array_flip($changed)),
            ]);
        });

        return back()->with('success', "{$resource->label()} saved.");
    }

    /** Links one of the asset's log sheets (ADR-0031 §6). */
    public function link(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $sheet = Access::managedSheet((string) $request->validate(['sheet_id' => ['required', 'uuid']])['sheet_id']);
        SheetAssets::link($sheet, $resource, $this->user());

        return back()->with('success', "{$resource->code} linked to {$sheet->code}.");
    }

    public function unlink(string $resource, string $sheet): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $sheet = Access::managedSheet($sheet);
        SheetAssets::unlink($sheet, $resource, $this->user());

        return back()->with('success', "{$resource->code} unlinked from {$sheet->code}. Its entries keep naming it.");
    }

    /**
     * A new log sheet from a starter template, linked to the asset. For
     * when no shared sheet fits: one Temperature log serves every cold
     * room, so a sheet is never made per asset by itself.
     */
    public function fromStarter(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(StarterTemplates::all()))],
            'logbook_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z][A-Za-z0-9]{1,11}$/'],
        ], ['code.regex' => 'Letters and digits, starting with a letter (2 to 12).']);
        $logbook = Access::managedLogbook($data['logbook_id']);
        $template = StarterTemplates::all()[$data['template']];
        if (LogSheet::query()->whereRaw('upper(code) = ?', [strtoupper($data['code'])])->exists()) {
            throw ValidationException::withMessages(['code' => 'Another log sheet has this code.']);
        }
        $sheet = DB::transaction(function () use ($logbook, $data, $template, $resource) {
            $sheet = Sheets::create($logbook, [
                'name' => $data['name'], 'code' => $data['code'], 'description' => $template['description'], 'review_required' => $template['review_required'],
            ], $template['fields'], $this->user(), 'starter '.$data['template'].' for '.$resource->code);
            if (isset($template['recording']) || isset($template['location'])) {
                Sheets::saveDraft($sheet, $template['fields'], $this->user(), $template['location'] ?? null, isset($template['location']), $template['recording'] ?? null, isset($template['recording']));
            }
            SheetAssets::link($sheet->fresh(['draftVersion', 'publishedVersion']), $resource, $this->user());

            return $sheet;
        });

        return to_route('sheets.template', $sheet->id)->with('success', "{$sheet->code} created for {$resource->code}. Review its fields, then publish.");
    }

    /** Assets linked from the log sheet's side. */
    public function linkFromSheet(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        $ids = (array) $request->validate(['resource_ids' => ['required', 'array', 'max:200'], 'resource_ids.*' => ['uuid']])['resource_ids'];
        $resources = LogResource::query()->whereIn('id', $ids)->get();
        foreach ($resources as $resource) {
            SheetAssets::link($sheet, $resource, $this->user());
        }

        return back()->with('success', $resources->count().' '.($resources->count() === 1 ? 'asset' : 'assets').' linked to '.$sheet->code.'.');
    }

    public function unlinkFromSheet(string $sheet, string $resource): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        $resource = LogResource::query()->findOrFail($resource);
        SheetAssets::unlink($sheet, $resource, $this->user());

        return back()->with('success', "{$resource->code} unlinked from {$sheet->code}.");
    }

    // ---- Asset templates (ADR-0031 §3) ----

    public function templates(): Response
    {
        return Inertia::render('resources/templates', [
            'templates' => AssetTemplates::all(withInactive: true),
            'starters' => collect(StarterTemplates::all())->map(fn ($t, $key) => ['value' => $key, 'label' => $t['name']])->values(),
            'canManage' => Gate::allows('logbook.manage'),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $template = AssetTemplates::save(null, $request->all(), $this->user());

        return back()->with('success', "Template {$template->name} added.");
    }

    public function updateTemplate(Request $request, string $template): RedirectResponse
    {
        $template = AssetTemplates::save(AssetTemplate::query()->findOrFail($template), $request->all(), $this->user());

        return back()->with('success', "Template {$template->name} saved (version {$template->version}). Assets registered before keep the version they were made with.");
    }

    /**
     * What the asset page shows besides fuel (ADR-0031 §10): the sheets
     * that record it, its schedules, recent records, checks, exceptions,
     * tasks and investigations. Each row links to the record itself; only
     * what the viewer may read is listed.
     *
     * @param  callable(mixed): (string|null)  $local
     * @return array<string, mixed>
     */
    private function operations(LogResource $resource, callable $local): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays(30);
        $linked = LogSheet::query()->whereIn('id', DB::table('log_sheet_resources')->where('log_resource_id', $resource->id)->select('log_sheet_id'))
            ->with(['logbook:id,name,status', 'publishedVersion', 'draftVersion'])->orderBy('code')->get();
        $visibleSheets = Access::sheets()->pluck('id')->flip();
        $manages = Gate::allows('logbook.manage');
        $occurrences = fn () => Schedules::visible()->where('resource_id', $resource->id);
        $starters = StarterTemplates::all();

        return [
            'sheets' => $linked->map(fn (LogSheet $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'logbook' => $s->logbook->name, 'status' => $s->status,
                'mode' => Recording::LABELS[Recording::mode($s)], 'visible' => $visibleSheets->has($s->id),
                'canUnlink' => $manages && Access::managesLogbook($s->logbook),
            ])->values(),
            // Sheets a manager could link: they name an asset of this type and are not linked yet.
            'linkable' => ! $manages ? [] : Access::sheets()->where('status', '!=', 'archived')->whereNotIn('id', $linked->pluck('id'))
                ->with(['logbook:id,name,status', 'publishedVersion', 'draftVersion'])->orderBy('code')->get()
                ->filter(fn (LogSheet $s) => Access::managesLogbook($s->logbook) && SheetAssets::refusal($s, $resource) === null)
                ->map(fn (LogSheet $s) => ['value' => $s->id, 'label' => "{$s->code} · {$s->name} ({$s->logbook->name})"])->values(),
            'suggested' => collect($resource->template['suggested_sheets'] ?? [])->filter(fn ($key) => isset($starters[$key]))
                ->map(fn ($key) => ['key' => $key, 'name' => $starters[$key]['name'], 'code' => $starters[$key]['code']])->values(),
            'logbooks' => $manages ? Access::managedLogbooks()->orderBy('name')->get()->filter(fn ($l) => $l->isActive())
                ->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->values() : [],
            'schedules' => Access::limit(Schedule::query(), array_keys(Access::CAPABILITIES))->where('resource_id', $resource->id)->with('sheet:id,code,name')->get()
                ->map(fn (Schedule $s) => [
                    'id' => $s->id, 'sheet' => $s->sheet->code.' · '.$s->sheet->name, 'state' => Schedules::state($s),
                    'times' => implode(', ', array_column(Schedules::times($s), 'due')), 'frequency' => $s->frequency,
                ])->values(),
            'checks' => Schedules::figures($occurrences()->where('due_at', '>=', $since), $now),
            'missed' => $occurrences()->where('status', 'missed')->with('sheet:id,code')->orderByDesc('due_at')->limit(10)->get()
                ->map(fn (ScheduleOccurrence $o) => ['id' => $o->id, 'sheet' => $o->sheet->code, 'dueAt' => $local($o->due_at)])->values(),
            'records' => Access::entries()->where('resource_id', $resource->id)->where('status', '!=', 'draft')
                ->with(['sheet:id,code,name', 'creator:id,name', 'version:id,fields'])->orderByDesc('occurred_at')->limit(15)->get()
                ->map(fn (Entry $e) => [
                    'id' => $e->id, 'reference' => $e->reference, 'sheet' => $e->sheet->code.' · '.$e->sheet->name, 'occurredAt' => $local($e->occurred_at),
                    'status' => $e->status, 'creator' => $e->creator->name, 'outOfRange' => count(Fields::deviations($e->version->fields, $e->values ?? [])),
                    'fuel' => $e->calculation['estimated_l'] ?? null,
                ])->values(),
            'recordCount' => Access::entries()->where('resource_id', $resource->id)->where('status', '!=', 'draft')->count(),
            'exceptions' => Exceptions::visible()->where('resource_id', $resource->id)->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (LogException $x) => ['id' => $x->id, 'reference' => $x->reference, 'title' => $x->title, 'severity' => $x->severity, 'status' => $x->status, 'at' => $local($x->created_at)])->values(),
            'tasks' => Tasks::visible()->where('resource_id', $resource->id)->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Task $t) => ['id' => $t->id, 'reference' => $t->reference, 'title' => $t->title, 'status' => $t->status, 'dueAt' => $local($t->due_at)])->values(),
            'investigations' => Investigations::visible()->where('resource_id', $resource->id)->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Investigation $i) => ['id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'status' => $i->status])->values(),
        ];
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $location = DB::transaction(function () use ($request) {
            $location = Location::query()->create($this->locationInput($request));
            AuditTrail::record('location.created', $location, $this->user());

            return $location;
        });

        return back()->with('success', "{$location->name} added.");
    }

    public function updateLocation(Request $request, string $location): RedirectResponse
    {
        $location = Location::query()->findOrFail($location);
        $data = $this->locationInput($request);
        // No loops: a place cannot sit inside itself or one of its own places.
        for ($at = $data['parent_id'] ?? null, $guard = 0; $at !== null && $guard < 10; $guard++) {
            if ($at === $location->id) {
                throw ValidationException::withMessages(['parent_id' => 'A place cannot sit inside itself.']);
            }
            $at = Location::query()->whereKey($at)->value('parent_id');
        }
        DB::transaction(function () use ($location, $data) {
            $location->update($data);
            AuditTrail::record('location.updated', $location, $this->user());
        });

        return back()->with('success', "{$location->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceInput(Request $request, ?LogResource $resource = null): array
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $organizationId = app(CurrentTenant::class)->require()->id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('log_resources', 'code')->where('organization_id', $organizationId)->ignore($resource?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:48'],
            'kind' => ['nullable', Rule::in(LogResource::KINDS)],
            'status' => ['nullable', Rule::in(LogResource::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'uuid', Rule::exists('log_locations', 'id')->where('organization_id', $organizationId)],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'responsible_id' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array'],
            'active' => ['boolean'],
        ], ['code.regex' => 'Letters, digits and dashes, such as GEN-01.', 'code.unique' => 'Another resource has this code.']);
        if (($data['responsible_id'] ?? null) !== null && ! WorkspaceMember::query()->ofUser($data['responsible_id'])->exists()) {
            throw ValidationException::withMessages(['responsible_id' => 'Choose a member of this workspace.']);
        }
        [$metadata, $errors] = AssetTemplates::metadataInput($data['metadata'] ?? []);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $kind = $data['kind'] ?? $resource->kind ?? 'asset';
        // A monitored place says where it is.
        if ($kind === 'monitored_location' && ($data['location_id'] ?? null) === null) {
            throw ValidationException::withMessages(['location_id' => 'Choose the location this monitored place is.']);
        }
        $out = ['code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'kind' => $kind];
        foreach (['description', 'location_id', 'manufacturer', 'model', 'serial_number', 'responsible_id'] as $key) {
            $value = $data[$key] ?? null;
            $out[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        // The details form sends every detail; the older quick form sends none and keeps them.
        if ($request->has('metadata')) {
            $out['metadata'] = $metadata === [] ? null : $metadata;
        } elseif ($resource === null) {
            $out['metadata'] = null;
        }
        // The older form sends "active"; the newer one the status.
        if (isset($data['status'])) {
            $out['status'] = $data['status'];
        } elseif (array_key_exists('active', $data)) {
            $out['status'] = $data['active'] ? 'active' : ($resource !== null && $resource->status === 'retired' ? 'retired' : 'inactive');
        }

        return $out;
    }

    /**
     * An optional first fuel rate given while registering (ADR-0031 §8).
     *
     * @return array<string, mixed>|null
     */
    private function fuelInput(Request $request): ?array
    {
        $fuel = $request->input('fuel');
        if (! is_array($fuel) || ! isset($fuel['rate']) || $fuel['rate'] === '') {
            return null;
        }

        return [
            'fuel_type' => $fuel['fuel_type'] ?? 'diesel', 'method' => $fuel['method'] ?? null, 'rate' => $fuel['rate'],
            'effective_from' => $fuel['effective_from'] ?? CarbonImmutable::now(Workspace::current()->timezone ?? 'UTC')->format('Y-m-d\TH:i'),
            'note' => 'Set when registered',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationInput(Request $request): array
    {
        $organizationId = app(CurrentTenant::class)->require()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('log_locations', 'id')->where('organization_id', $organizationId)],
            'active' => ['boolean'],
        ]);
    }
}
