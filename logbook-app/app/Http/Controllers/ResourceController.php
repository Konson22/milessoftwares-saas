<?php

namespace App\Http\Controllers;

use App\Logbook\AuditTrail;
use App\Logbook\Fuel;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\MeterEvent;
use App\Models\Workspace;
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
 * Resources and locations (ADR-0026 §2.10): named once, chosen in Resource
 * fields and on tasks. Everyone in Logbook reads the list; managers keep
 * it. Never deleted once in use: made inactive instead.
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

        return Inertia::render('resources/index', [
            'resources' => LogResource::query()->with('location.parent')->orderByDesc('active')->orderBy('code')->get()
                ->map(fn (LogResource $r) => [
                    'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'description' => $r->description,
                    'locationId' => $r->location_id, 'location' => $r->location?->path(), 'active' => $r->active,
                    'fuelRate' => ($rate = $rates->get($r->id)) !== null ? (float) $rate->rate.' '.$rate->unit() : null,
                ])->values(),
            'locations' => $locations->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'path' => $l->path(), 'parentId' => $l->parent_id, 'active' => $l->active])->values(),
            'types' => LogResource::query()->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /**
     * One resource with its fuel rates and meter events (ADR-0030 §4.6,
     * §4.8). Everyone may read them; managers add to them.
     */
    public function show(string $resource): Response
    {
        $resource = LogResource::query()->with('location.parent')->findOrFail($resource);
        $tz = Workspace::current()->timezone ?? 'UTC';
        $local = fn ($t) => $t?->setTimezone($tz)->format('Y-m-d H:i');
        $rates = FuelRate::query()->where('log_resource_id', $resource->id)->with('creator:id,name')
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')->get();
        $current = Fuel::rateAt($resource->id, CarbonImmutable::now());

        return Inertia::render('resources/show', [
            'resource' => [
                'id' => $resource->id, 'code' => $resource->code, 'name' => $resource->name, 'type' => $resource->type,
                'description' => $resource->description, 'location' => $resource->location?->path(), 'active' => $resource->active,
            ],
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
        $resource = DB::transaction(function () use ($request) {
            $resource = LogResource::query()->create($this->resourceInput($request));
            AuditTrail::record('resource.created', $resource, $this->user());

            return $resource;
        });

        return back()->with('success', "{$resource->label()} added.");
    }

    public function update(Request $request, string $resource): RedirectResponse
    {
        $resource = LogResource::query()->findOrFail($resource);
        DB::transaction(function () use ($request, $resource) {
            $before = ['code' => $resource->code, 'name' => $resource->name, 'active' => $resource->active];
            $resource->update($this->resourceInput($request, $resource));
            AuditTrail::record('resource.updated', $resource, $this->user(), ['from' => $before, 'to' => ['code' => $resource->code, 'name' => $resource->name, 'active' => $resource->active]]);
        });

        return back()->with('success', "{$resource->label()} saved.");
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

        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('log_resources', 'code')->where('organization_id', $organizationId)->ignore($resource?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:48'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'uuid', Rule::exists('log_locations', 'id')->where('organization_id', $organizationId)],
            'active' => ['boolean'],
        ], ['code.regex' => 'Letters, digits and dashes, such as GEN-01.', 'code.unique' => 'Another resource has this code.']);
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
