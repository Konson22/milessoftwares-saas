<?php

namespace App\Http\Controllers\Mel;

use App\Mel\LinkedProjects;
use App\Mel\Members;
use App\Mel\Models\Location;
use App\Mel\Models\Partner;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeAccess;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Settings;
use App\Mel\Numbers;
use App\Mel\Programmes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programmes: the list, one programme's page (units, periods, access) and
 * setting them up.
 */
class ProgrammeController extends Controller
{
    public function __construct(private readonly Programmes $programmes) {}

    public function index(Request $request): Response
    {
        $archived = $request->boolean('archived');

        $programmes = $this->access()->programmes()
            ->withCount(['indicators', 'units'])
            ->with('manager')
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('code')
            ->get();

        return Inertia::render('mel/programmes/index', [
            'archived' => $archived,
            'programmes' => $programmes->map(fn (Programme $programme) => [
                'id' => $programme->id,
                'code' => $programme->code,
                'name' => $programme->name,
                'status' => $programme->status,
                'startsOn' => $programme->starts_on->toDateString(),
                'endsOn' => $programme->ends_on->toDateString(),
                'manager' => $programme->manager?->name,
                'indicators' => $programme->indicators_count,
                'units' => $programme->units_count,
            ]),
            'canCreate' => $this->access()->can('mel.programmes.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('mel/programmes/form', [
            'programme' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $programme = $this->programmes->create($this->validated($request, null), $this->user());

        return to_route('mel.programmes.show', $programme->id)->with('success', 'Programme created. Add its reporting units and periods next.');
    }

    public function show(string $programme): Response
    {
        $programme = $this->programme($programme);
        $programme->load(['manager', 'locations', 'units.partner', 'units.location']);
        $access = $this->access();
        $unitIds = $access->unitIds($programme);
        $manages = $access->can('mel.programmes.manage');
        // Live from Projects: progress and spending as Projects works them out.
        $projects = LinkedProjects::figures($programme->units, $access);

        return Inertia::render('mel/programmes/show', [
            'programme' => [
                ...$this->summary($programme),
                'objective' => $programme->objective,
                'description' => $programme->description,
                'currency' => $programme->currency,
                'budgetMinor' => $programme->budget_minor,
                'fundingSummary' => $programme->funding_summary,
                'manager' => $programme->manager?->name,
                'locations' => $programme->locations->map(fn (Location $location) => $location->path())->all(),
            ],
            'units' => $programme->units
                ->filter(fn (ProgrammeUnit $unit) => $unitIds === null || in_array($unit->id, $unitIds, true))
                ->map(fn (ProgrammeUnit $unit) => [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'name' => $unit->name,
                    'kind' => $unit->kind,
                    'partnerId' => $unit->partner_id,
                    'partner' => $unit->partner?->name,
                    'locationId' => $unit->location_id,
                    'location' => $unit->location?->name,
                    'archived' => $unit->archived_at !== null,
                    // Read through the link to Projects (LinkedProjects), with the project's
                    // milestones once the link is on.
                    'project' => $unit->linked_project_id === null || ! isset($projects[$unit->linked_project_id]) ? null : [
                        ...$projects[$unit->linked_project_id],
                        'milestones' => [],
                    ],
                ])->values(),
            'periods' => $programme->periods()->get()
                ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($programme, $period->starts_on, $period->ends_on))
                ->map(fn (ReportingPeriod $period) => [
                    'id' => $period->id,
                    'frequency' => $period->frequency,
                    'label' => $period->label,
                    'startsOn' => $period->starts_on->toDateString(),
                    'endsOn' => $period->ends_on->toDateString(),
                    'dueOn' => $period->due_on->toDateString(),
                    'status' => $period->status,
                ])->values(),
            'grants' => $manages ? ProgrammeAccess::query()->inEffect()->where('programme_id', $programme->id)->with(['user', 'unit', 'granter'])->orderBy('created_at')->get()
                ->map(fn (ProgrammeAccess $grant) => [
                    'id' => $grant->id,
                    'user' => $grant->user->name,
                    'email' => $grant->user->email,
                    'unit' => $grant->unit?->code,
                    'periodsFrom' => $grant->periods_from?->toDateString(),
                    'periodsTo' => $grant->periods_to?->toDateString(),
                    'expiresOn' => $grant->expires_on?->toDateString(),
                    'grantedBy' => $grant->granter->name,
                ])->values() : null,
            'options' => $manages ? [
                'partners' => Partner::query()->whereNull('archived_at')->orderBy('name')->get()->map(fn (Partner $partner) => ['value' => $partner->id, 'label' => $partner->name]),
                'locations' => $this->locationOptions(),
                'members' => Members::options(),
                'unitKinds' => self::options(ProgrammeUnit::KINDS),
                // Projects' records are read through the link (M3), off for now.
                'projects' => [],
            ] : null,
            'can' => [
                'manage' => $manages && ! $programme->isArchived(),
                'linkProjects' => LinkedProjects::enabled() && $access->can('mel.projects.link') && ! $programme->isArchived(),
            ],
        ]);
    }

    public function edit(string $programme): Response
    {
        $programme = $this->programme($programme);
        $programme->load('locations');

        return Inertia::render('mel/programmes/form', [
            'programme' => [
                ...$this->summary($programme),
                'objective' => $programme->objective,
                'description' => $programme->description,
                'currency' => $programme->currency,
                'budget' => $programme->budget_minor === null ? null : bcdiv((string) $programme->budget_minor, '100', 2),
                'fundingSummary' => $programme->funding_summary,
                'managerId' => $programme->manager_id,
                'locationIds' => $programme->locations->pluck('id')->all(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->programmes->update($programme, [
            ...$this->validated($request, $programme),
            'status' => $request->validate(['status' => ['required', Rule::in(Programme::STATUSES)]])['status'],
        ], $this->user());

        return to_route('mel.programmes.show', $programme->id)->with('success', 'Programme saved.');
    }

    public function archive(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $request->boolean('restore')
            ? $this->programmes->restore($programme, $this->user())
            : $this->programmes->archive($programme, $this->user());

        return back()->with('success', $request->boolean('restore') ? 'Programme restored.' : 'Programme archived. Its history stays in reports.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Programme $programme): array
    {
        return [
            'id' => $programme->id,
            'code' => $programme->code,
            'name' => $programme->name,
            'status' => $programme->status,
            'startsOn' => $programme->starts_on->toDateString(),
            'endsOn' => $programme->ends_on->toDateString(),
            'archived' => $programme->isArchived(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'members' => Members::options(),
            'locations' => $this->locationOptions(),
            'statuses' => self::options(Programme::STATUSES),
            'defaultCurrency' => Settings::current()->currency,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function locationOptions(): array
    {
        return array_values(Location::query()->whereNull('archived_at')->with('parent.parent.parent')->get()
            ->map(fn (Location $location) => ['value' => $location->id, 'label' => $location->path()])
            ->sortBy('label')->all());
    }

    /**
     * @return array{code: string, name: string, objective: string|null, description: string|null, starts_on: string, ends_on: string, currency: string, budget_minor: int|null, funding_summary: string|null, manager_id: string|null, location_ids: list<string>}
     */
    private function validated(Request $request, ?Programme $programme): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('mel_programmes', 'code')->where('organization_id', tenant_id())->ignore($programme?->id)],
            'name' => ['required', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'funding_summary' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'uuid', Members::rule()],
            'location_ids' => ['array'],
            'location_ids.*' => ['uuid', Rule::exists('mel_locations', 'id')->where('organization_id', tenant_id())],
        ], ['code.regex' => 'Letters, digits, - and _ only.']);

        return [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'objective' => $data['objective'] ?? null,
            'description' => $data['description'] ?? null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'currency' => strtoupper($data['currency']),
            'budget_minor' => ($budget = Numbers::parse($data['budget'] ?? null)) === null ? null : (int) Numbers::round(Numbers::mul($budget, '100'), 0),
            'funding_summary' => $data['funding_summary'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'location_ids' => array_values($data['location_ids'] ?? []),
        ];
    }
}
