<?php

namespace App\Http\Controllers\Mel;

use App\Mel\LinkedProjects;
use App\Mel\Members;
use App\Mel\Models\ProgrammeAccess;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Periods;
use App\Mel\Programmes;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        abort_unless(LinkedProjects::enabled(), 404);
        $data = $request->validate([
            'linked_project_id' => ['nullable', 'uuid'],
            'shares_with_project_reports' => ['sometimes', 'boolean'],
        ]);
        $projectId = $data['linked_project_id'] ?? null;
        $shares = (bool) ($data['shares_with_project_reports'] ?? false);

        // A new link is checked in Projects first, as this member: the
        // organization has both apps and the member may see the project.
        // Unlinking needs nothing from Projects.
        if ($projectId !== null && strtolower($projectId) !== $unit->linked_project_id) {
            $found = LinkedProjects::project($this->access(), $projectId);
            if ($found === null || ! $found->ok()) {
                throw ValidationException::withMessages(['linked_project_id' => LinkedProjects::explain($found) ?? 'Projects is not connected.']);
            }
        }

        $this->programmes->linkProject($unit, $projectId, $this->user(), $shares);

        return back()->with('success', $projectId === null ? 'Project unlinked.' : 'Project linked.');
    }

    /**
     * Who the unit's results are released to in its project's reports
     * (ADR-0019 §24). The partner is checked in Projects, as this member:
     * it must be active on the linked project now.
     */
    public function projectAudience(Request $request, string $programme, string $unit): RedirectResponse
    {
        $programme = $this->programme($programme);
        $unit = $programme->units()->findOrFail($unit);
        abort_unless(LinkedProjects::enabled(), 404);
        $data = $request->validate([
            'audience' => ['required', Rule::in(Programmes::AUDIENCES)],
            'partner_organization_id' => ['nullable', 'uuid', Rule::requiredIf($request->input('audience') === 'partner')],
        ]);
        $partnerId = $data['audience'] === 'owner' ? null : ($data['partner_organization_id'] ?? null);
        $partnerName = null;
        if ($partnerId !== null && $unit->linked_project_id !== null) {
            $participants = LinkedProjects::participants($this->access(), $unit->linked_project_id);
            if ($participants === null) {
                throw ValidationException::withMessages(['partner_organization_id' => 'Projects cannot be reached to check the partner. Try again shortly.']);
            }
            $partnerName = $participants[strtolower($partnerId)] ?? null;
            if ($partnerName === null) {
                throw ValidationException::withMessages(['partner_organization_id' => 'That organization is not active on the linked project.']);
            }
        }

        $this->programmes->setProjectAudience($unit, $data['audience'], $partnerId, $partnerName, $this->user());

        return back()->with('success', 'Who sees the results in project reports was saved.');
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
            'user_id' => ['required', 'uuid', Members::rule()],
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
                Rule::unique('mel_programme_units', 'code')->where('programme_id', $programmeId)->ignore($unit?->id)],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(ProgrammeUnit::KINDS)],
            'partner_id' => ['nullable', 'uuid', Rule::exists('mel_partners', 'id')->where('organization_id', tenant_id())],
            'location_id' => ['nullable', 'uuid', Rule::exists('mel_locations', 'id')->where('organization_id', tenant_id())],
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
