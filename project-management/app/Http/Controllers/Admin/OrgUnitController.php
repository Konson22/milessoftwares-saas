<?php

namespace App\Http\Controllers\Admin;

use App\Actions\OrgUnits;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\OrgUnit;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration, Departments and teams: how the organization is arranged.
 */
class OrgUnitController extends Controller
{
    public function index(): Response
    {
        $organization = app(CurrentOrganization::class)->require();
        $members = OrganizationMembership::query()->where('organization_id', $organization->id)
            ->whereNotNull('org_unit_id')->groupBy('org_unit_id')
            ->selectRaw('org_unit_id, count(*) as total')->pluck('total', 'org_unit_id');

        return Inertia::render('portal/admin/org-units', [
            'units' => OrgUnit::query()->orderBy('name')->get()->map(fn (OrgUnit $unit) => [
                'id' => $unit->id,
                'parentId' => $unit->parent_id,
                'kind' => $unit->kind,
                'name' => $unit->name,
                'code' => $unit->code,
                'archived' => $unit->archived_at !== null,
                'members' => (int) ($members[$unit->id] ?? 0),
            ])->values(),
            'kinds' => collect(OrgUnit::KINDS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        OrgUnits::create($this->details($request), $this->member($request));

        return back()->with('success', 'Unit added.');
    }

    public function update(Request $request, OrgUnit $orgUnit): RedirectResponse
    {
        OrgUnits::update($orgUnit, $this->details($request), $this->member($request));

        return back()->with('success', 'Unit saved.');
    }

    public function archive(Request $request, OrgUnit $orgUnit): RedirectResponse
    {
        OrgUnits::archive($orgUnit, $this->member($request));

        return back()->with('success', "{$orgUnit->name} archived.");
    }

    public function restore(Request $request, OrgUnit $orgUnit): RedirectResponse
    {
        OrgUnits::restore($orgUnit, $this->member($request));

        return back()->with('success', "{$orgUnit->name} restored.");
    }

    /**
     * @return array{kind: string, name: string, code: string|null, parent_id: string|null}
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(OrgUnit::KINDS))],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:24'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        return [
            'kind' => $validated['kind'],
            'name' => trim($validated['name']),
            'code' => $this->text($validated['code'] ?? null),
            'parent_id' => $validated['parent_id'] ?? null,
        ];
    }
}
