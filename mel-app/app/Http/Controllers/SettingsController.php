<?php

namespace App\Http\Controllers;

use App\Mel\AuditTrail;
use App\Models\Dimension;
use App\Models\DimensionCategory;
use App\Models\Location;
use App\Models\Partner;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M&E settings (mel.settings.manage): review rules, performance thresholds,
 * locations, implementing partners and disaggregation dimensions. Nothing
 * here is deleted once used; it is archived.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function show(): Response
    {
        $settings = Workspace::settings();

        return Inertia::render('settings/index', [
            'settings' => [
                'provisioned' => $settings->exists,
                'verifierMustDiffer' => $settings->verifier_must_differ,
                'publisherMustDiffer' => $settings->publisher_must_differ,
                'onTrackPercent' => $settings->on_track_percent,
                'attentionPercent' => $settings->attention_percent,
            ],
            'locations' => Location::query()->with('parent.parent.parent')->get()
                ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name, 'code' => $location->code, 'parentId' => $location->parent_id, 'path' => $location->path(), 'archived' => $location->archived_at !== null])
                ->sortBy('path')->values(),
            'partners' => Partner::query()->orderBy('name')->get()
                ->map(fn (Partner $partner) => ['id' => $partner->id, 'name' => $partner->name, 'kind' => $partner->kind, 'archived' => $partner->archived_at !== null]),
            'dimensions' => Dimension::query()->with('categories')->orderBy('name')->get()->map(fn (Dimension $dimension) => [
                'id' => $dimension->id,
                'name' => $dimension->name,
                'isSensitive' => $dimension->is_sensitive,
                'archived' => $dimension->archived_at !== null,
                'categories' => $dimension->categories->map(fn (DimensionCategory $category) => ['id' => $category->id, 'name' => $category->name, 'archived' => $category->archived_at !== null])->values(),
            ]),
            'partnerKinds' => self::options(Partner::KINDS, fn ($kind) => $kind === 'un_agency' ? 'UN agency' : ($kind === 'ngo' ? 'NGO' : ucfirst($kind))),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'verifier_must_differ' => ['required', 'boolean'],
            'publisher_must_differ' => ['required', 'boolean'],
            'on_track_percent' => ['required', 'integer', 'min:1', 'max:200'],
            'attention_percent' => ['required', 'integer', 'min:0', 'lt:on_track_percent'],
        ], ['attention_percent.lt' => 'Must be below the on-track threshold.']);

        $workspace = Workspace::current();
        abort_if($workspace === null, 409, 'M&E is not set up for this organization yet.');

        DB::transaction(function () use ($workspace, $data) {
            $workspace->fill($data);
            $changes = collect($workspace->getDirty())->map(fn ($value) => is_bool($value) ? ($value ? 'on' : 'off') : $value)->all();
            $workspace->save();
            $this->audit->record('settings.updated', $workspace, $this->user(), $changes);
        });

        return back()->with('success', 'Settings saved.');
    }

    public function saveLocation(Request $request, ?string $location = null): RedirectResponse
    {
        $model = $location === null ? new Location : Location::query()->findOrFail($location);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:32'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('organization_id', tenant_id()), Rule::notIn(array_filter([$model->id]))],
            'archived' => ['boolean'],
        ]);

        DB::transaction(function () use ($model, $data) {
            $created = ! $model->exists;
            $model->fill([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'archived_at' => ($data['archived'] ?? false) ? ($model->archived_at ?? now()) : null,
            ])->save();
            $this->audit->record($created ? 'location.created' : 'location.updated', $model, $this->user());
        });

        return back()->with('success', 'Location saved.');
    }

    public function savePartner(Request $request, ?string $partner = null): RedirectResponse
    {
        $model = $partner === null ? new Partner : Partner::query()->findOrFail($partner);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('partners', 'name')->where('organization_id', tenant_id())->ignore($model->id)],
            'kind' => ['required', Rule::in(Partner::KINDS)],
            'archived' => ['boolean'],
        ]);

        DB::transaction(function () use ($model, $data) {
            $created = ! $model->exists;
            $model->fill(['name' => $data['name'], 'kind' => $data['kind'], 'archived_at' => ($data['archived'] ?? false) ? ($model->archived_at ?? now()) : null])->save();
            $this->audit->record($created ? 'partner.created' : 'partner.updated', $model, $this->user());
        });

        return back()->with('success', 'Partner saved.');
    }

    /**
     * A dimension and its categories. Categories are renamed, added or
     * archived, never deleted: results may use them.
     */
    public function saveDimension(Request $request, ?string $dimension = null): RedirectResponse
    {
        $model = $dimension === null ? new Dimension : Dimension::query()->findOrFail($dimension);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('dimensions', 'name')->where('organization_id', tenant_id())->ignore($model->id)],
            'is_sensitive' => ['boolean'],
            'archived' => ['boolean'],
            'categories' => ['required', 'array', 'min:2', 'max:40'],
            'categories.*.id' => ['nullable', 'uuid'],
            'categories.*.name' => ['required', 'string', 'max:80', 'distinct'],
            'categories.*.archived' => ['boolean'],
        ], ['categories.min' => 'A dimension needs at least two categories.']);

        DB::transaction(function () use ($model, $data) {
            $created = ! $model->exists;
            $model->fill([
                'name' => $data['name'],
                'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
                'archived_at' => ($data['archived'] ?? false) ? ($model->archived_at ?? now()) : null,
            ])->save();

            foreach (array_values($data['categories']) as $i => $row) {
                $category = ($row['id'] ?? null) === null
                    ? new DimensionCategory(['dimension_id' => $model->id])
                    : DimensionCategory::query()->where('dimension_id', $model->id)->whereKey($row['id'])->firstOrFail();
                $category->fill([
                    'name' => $row['name'],
                    'sort_order' => $i,
                    'archived_at' => ($row['archived'] ?? false) ? ($category->archived_at ?? now()) : null,
                ])->save();
            }

            $this->audit->record($created ? 'dimension.created' : 'dimension.updated', $model, $this->user(), ['categories' => items($data['categories'])->pluck('name')->all()]);
        });

        return back()->with('success', 'Dimension saved.');
    }
}
