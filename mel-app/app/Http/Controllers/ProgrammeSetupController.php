<?php

namespace App\Http\Controllers;

use App\Mel\Periods;
use App\Mel\Programmes;
use App\Models\LinkedProject;
use App\Models\ProgrammeAccess;
use App\Models\ProgrammeUnit;
use App\Models\ReportingPeriod;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A programme's reporting units, periods and access grants
 * (mel.programmes.manage), and links to Projects (mel.projects.link).
 */
class ProgrammeSetupController extends Controller
{
    public function __construct(
        private readonly Programmes $programmes,
        private readonly Periods $periods,
    ) {}

    public function storeUnit(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->saveUnit($programme, null, $this->unitData($request, $programme->id, null), $this->user());

        return back()->with('success', 'Unit added.');
    }

    public function updateUnit(Request $request, string $programme, string $unit): RedirectResponse
    {
        $programme = $this->programme($programme);
        $unit = $programme->units()->findOrFail($unit);
        $this->programmes->saveUnit($programme, $unit, $this->unitData($request, $programme->id, $unit), $this->user());

        return back()->with('success', 'Unit saved.');
    }

    public function archiveUnit(Request $request, string $programme, string $unit): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->assertNotArchived($programme);
        $this->programmes->archiveUnit($programme->units()->findOrFail($unit), ! $request->boolean('restore'), $this->user());

        return back()->with('success', $request->boolean('restore') ? 'Unit restored.' : 'Unit archived.');
    }

    public function linkProject(Request $request, string $programme, string $unit): RedirectResponse
    {
        $programme = $this->programme($programme);
        $unit = $programme->units()->findOrFail($unit);
        $data = $request->validate(['linked_project_id' => ['nullable', 'uuid']]);
        $project = ($data['linked_project_id'] ?? null) === null ? null : LinkedProject::query()->whereKey($data['linked_project_id'])->firstOrFail();

        $this->programmes->linkProject($unit, $project, $this->user());

        return back()->with('success', $project === null ? 'Project unlinked.' : "Linked to {$project->label()}.");
    }

    public function generatePeriods(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->assertNotArchived($programme);
        $data = $request->validate([
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual'])],
            'due_after_days' => ['required', 'integer', 'min:0', 'max:180'],
        ]);

        $added = $this->periods->generate($programme, $data['frequency'], (int) $data['due_after_days'], $this->user());

        return back()->with('success', $added === 0 ? 'Those periods exist already.' : "{$added} periods added.");
    }

    public function storePeriod(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->assertNotArchived($programme);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:48'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:ends_on'],
        ]);

        $this->periods->addCustom($programme, $data['label'], CarbonImmutable::parse($data['starts_on']), CarbonImmutable::parse($data['ends_on']), CarbonImmutable::parse($data['due_on']), $this->user());

        return back()->with('success', 'Period added.');
    }

    public function periodStatus(Request $request, string $programme, string $period): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->assertNotArchived($programme);
        $period = $programme->periods()->findOrFail($period);
        $status = $request->validate(['status' => ['required', Rule::in([ReportingPeriod::OPEN, ReportingPeriod::CLOSED])]])['status'];

        $this->periods->setStatus($period, $status, $this->user());

        return back()->with('success', $status === ReportingPeriod::CLOSED ? "{$period->label} closed." : "{$period->label} reopened.");
    }

    public function grant(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $data = $request->validate([
            'user_id' => ['required', 'uuid', WorkspaceMember::rule()],
            'unit_id' => ['nullable', 'uuid'],
            'periods_from' => ['nullable', 'date'],
            'periods_to' => ['nullable', 'date', 'after_or_equal:periods_from'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->programmes->grant($programme, $data, $this->user());

        return back()->with('success', 'Access given. What they can do there comes from their M&E role.');
    }

    public function revoke(string $programme, string $grant): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->revoke(ProgrammeAccess::query()->where('programme_id', $programme->id)->findOrFail($grant), $this->user());

        return back()->with('success', 'Access removed.');
    }

    /**
     * @return array{code: string, name: string, kind: string, partner_id: string|null, location_id: string|null}
     */
    private function unitData(Request $request, string $programmeId, ?ProgrammeUnit $unit): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('programme_units', 'code')->where('programme_id', $programmeId)->ignore($unit?->id)],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(ProgrammeUnit::KINDS)],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('organization_id', tenant_id())],
            'location_id' => ['nullable', 'uuid', Rule::exists('locations', 'id')->where('organization_id', tenant_id())],
        ], ['code.regex' => 'Letters, digits, - and _ only.']);

        return [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'kind' => $data['kind'],
            'partner_id' => $data['partner_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
        ];
    }
}
