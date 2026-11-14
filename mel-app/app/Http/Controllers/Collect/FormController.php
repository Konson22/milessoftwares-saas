<?php

namespace App\Http\Controllers\Collect;

use App\Collect\Definition;
use App\Collect\DefinitionCheck;
use App\Collect\Forms;
use App\Http\Controllers\Controller;
use App\Models\Collect\Assignment;
use App\Models\Collect\Form;
use App\Models\Collect\FormVersion;
use App\Models\Collect\Submission;
use App\Models\Programme;
use App\Models\ProgrammeUnit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forms & surveys: the library, one form's page, its details, the builder
 * (with autosave), publishing, versions, duplicating and archiving.
 */
class FormController extends Controller
{
    public function __construct(private readonly Forms $forms) {}

    public function index(Request $request): Response
    {
        $show = in_array($request->query('show'), ['draft', 'published', 'archived'], true) ? (string) $request->query('show') : 'current';
        $search = trim((string) $request->query('q', ''));
        $programmeId = is_string($request->query('programme')) ? $request->query('programme') : null;

        $forms = $this->scope()->forms()
            ->with(['programme', 'unit', 'currentVersion'])
            ->withCount([
                'submissions as submissions_count' => fn ($query) => $query->where('status', '<>', Submission::RECEIVING),
                'submissions as to_review_count' => fn ($query) => $query->whereIn('status', Submission::TO_REVIEW),
                'assignments as collectors_count' => fn ($query) => $query->where('status', Assignment::ACTIVE),
            ])
            ->when($show === 'current', fn ($query) => $query->where('status', '<>', Form::ARCHIVED), fn ($query) => $query->where('status', $show))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->when($programmeId !== null, fn ($query) => $query->where('programme_id', $programmeId))
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return Inertia::render('collect/forms/index', [
            'filters' => ['show' => $show, 'q' => $search, 'programme' => $programmeId],
            'forms' => $forms->map(fn (Form $form) => [
                'id' => $form->id,
                'name' => $form->name,
                'purpose' => $form->purpose,
                'status' => $form->status,
                'programme' => $form->programme?->code,
                'unit' => $form->unit?->code,
                'version' => $form->currentVersion?->number,
                'unpublishedChanges' => $form->status !== Form::ARCHIVED && $form->hasUnpublishedChanges(),
                'submissions' => (int) $form->getAttribute('submissions_count'),
                'toReview' => (int) $form->getAttribute('to_review_count'),
                'collectors' => (int) $form->getAttribute('collectors_count'),
                'updatedAt' => $form->updated_at->toIso8601String(),
            ]),
            'programmes' => $this->programmeOptions(),
            'canCreate' => $this->access()->can('mel.collect.design'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('collect/forms/edit', [
            'form' => null,
            'defaults' => ['programmeId' => is_string($request->query('programme')) ? $request->query('programme') : null],
            ...$this->detailOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $form = $this->forms->create($this->validated($request), $this->user());

        return to_route('collect.forms.build', $form->id)->with('success', 'Form created. Add its questions.');
    }

    public function show(string $form): Response
    {
        $form = $this->scope()->form($form);
        $form->load(['programme', 'unit', 'currentVersion', 'versions.publisher']);

        $byStatus = $this->scope()->submissions()->where('form_id', $form->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $byVersion = Submission::query()->where('form_id', $form->id)
            ->selectRaw('form_version_id, count(*) as total')->groupBy('form_version_id')->pluck('total', 'form_version_id');

        $access = $this->access();

        return Inertia::render('collect/forms/show', [
            'form' => [
                ...$this->summary($form),
                'description' => $form->description,
                'containsPersonalData' => $form->contains_personal_data,
                'acceptUntil' => $form->accept_until?->toIso8601String(),
                'archivedAt' => $form->archived_at?->toIso8601String(),
                'draftUpdatedAt' => $form->draft_updated_at->toIso8601String(),
                'questions' => count(Definition::questions($form->currentVersion->definition ?? $form->draft_definition)),
                'draftIssues' => count(DefinitionCheck::issues($form->draft_definition)),
            ],
            'versions' => $form->versions->map(fn (FormVersion $version) => [
                'id' => $version->id,
                'number' => $version->number,
                'note' => $version->note,
                'publishedBy' => $version->publisher->name,
                'publishedAt' => $version->published_at->toIso8601String(),
                'current' => $version->id === $form->current_version_id,
                'closedAt' => $version->closed_at?->toIso8601String(),
                'acceptUntil' => $version->accept_until?->toIso8601String(),
                'accepting' => $version->acceptsSubmissions(),
                'submissions' => (int) ($byVersion[$version->id] ?? 0),
            ])->values(),
            'counts' => collect(Submission::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($byStatus[$status] ?? 0)]),
            'collectors' => Assignment::query()->live()->where('form_id', $form->id)->count(),
            'can' => [
                'design' => $access->can('mel.collect.design'),
                'publish' => $access->can('mel.collect.publish'),
                'assign' => $access->can('mel.collect.assign'),
                'export' => $access->can('mel.collect.export'),
            ],
        ]);
    }

    public function edit(string $form): Response
    {
        $form = $this->scope()->form($form);

        return Inertia::render('collect/forms/edit', [
            'form' => [
                'id' => $form->id,
                'name' => $form->name,
                'description' => $form->description,
                'purpose' => $form->purpose,
                'programmeId' => $form->programme_id,
                'unitId' => $form->programme_unit_id,
                'containsPersonalData' => $form->contains_personal_data,
            ],
            'defaults' => ['programmeId' => null],
            ...$this->detailOptions(),
        ]);
    }

    public function update(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $this->forms->update($form, $this->validated($request), $this->user());

        return to_route('collect.forms.show', $form->id)->with('success', 'Form details saved.');
    }

    public function build(string $form): Response
    {
        $form = $this->scope()->form($form);
        $form->load('currentVersion');

        return Inertia::render('collect/forms/build', [
            'form' => $this->summary($form),
            'definition' => $form->draft_definition,
            'issues' => DefinitionCheck::issues($form->draft_definition),
            'savedAt' => $form->draft_updated_at->toIso8601String(),
            'unpublishedChanges' => $form->hasUnpublishedChanges(),
            'canPublish' => $this->access()->can('mel.collect.publish'),
            'acceptOldDays' => Forms::DEFAULT_ACCEPT_OLD_DAYS,
        ]);
    }

    public function saveDraft(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $data = $request->validate(['definition' => ['required', 'array']]);

        $this->forms->saveDraft($form, $data['definition'], $this->user());

        return back();
    }

    public function publish(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'accept_old_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $version = $this->forms->publish($form, $data['note'] ?? null, isset($data['accept_old_days']) ? (int) $data['accept_old_days'] : null, $this->user());

        return to_route('collect.forms.show', $form->id)->with('success', "Version {$version->number} published. Assigned collectors get it the next time their phone syncs.");
    }

    public function version(string $form, string $version): Response
    {
        $form = $this->scope()->form($form);
        $version = FormVersion::query()->where('form_id', $form->id)->with('publisher')->findOrFail($version);

        return Inertia::render('collect/forms/version', [
            'form' => $this->summary($form),
            'version' => [
                'id' => $version->id,
                'number' => $version->number,
                'note' => $version->note,
                'publishedBy' => $version->publisher->name,
                'publishedAt' => $version->published_at->toIso8601String(),
                'current' => $version->id === $form->current_version_id,
                'definition' => $version->definition,
            ],
        ]);
    }

    public function closeVersion(Request $request, string $form, string $version): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $version = FormVersion::query()->where('form_id', $form->id)->findOrFail($version);
        $data = $request->validate(['accept_until' => ['nullable', 'date', 'after:now']]);

        $this->forms->closeVersion($version, isset($data['accept_until']) ? CarbonImmutable::parse($data['accept_until']) : null, $this->user());

        return back()->with('success', "Version {$version->number} no longer accepts submissions".(isset($data['accept_until']) ? ' after the date chosen.' : '.'));
    }

    public function duplicate(string $form): RedirectResponse
    {
        $copy = $this->forms->duplicate($this->scope()->form($form), $this->user());

        return to_route('collect.forms.build', $copy->id)->with('success', 'Copy made. It is a draft until you publish it.');
    }

    public function archive(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $data = $request->validate(['accept_days' => ['nullable', 'integer', 'min:0', 'max:365']]);

        $this->forms->archive($form, (int) ($data['accept_days'] ?? 0), $this->user());

        return to_route('collect.forms.show', $form->id)->with('success', 'Form archived. Its submissions stay available.');
    }

    public function restore(string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $this->forms->restore($form, $this->user());

        return to_route('collect.forms.show', $form->id)->with('success', 'Form restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Form $form): array
    {
        $form->loadMissing(['programme', 'unit', 'currentVersion']);

        return [
            'id' => $form->id,
            'name' => $form->name,
            'purpose' => $form->purpose,
            'status' => $form->status,
            'programme' => $form->programme === null ? null : ['id' => $form->programme->id, 'label' => $form->programme->label()],
            'unit' => $form->unit?->label(),
            'version' => $form->currentVersion?->number,
        ];
    }

    /**
     * @return array{name: string, description?: string|null, purpose: string, programme_id?: string|null, programme_unit_id?: string|null, contains_personal_data?: bool}
     */
    private function validated(Request $request): array
    {
        $access = $this->access();
        $reachable = $access->programmes()->whereNull('archived_at')->pluck('id')->all();

        /** @var array{name: string, description?: string|null, purpose: string, programme_id?: string|null, programme_unit_id?: string|null, contains_personal_data?: bool} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'purpose' => ['required', Rule::in(Form::PURPOSES)],
            // Without org-wide access, a form belongs to one of the member's programmes.
            'programme_id' => [$access->reachesEveryProgramme() ? 'nullable' : 'required', 'uuid', Rule::in($reachable)],
            'programme_unit_id' => ['nullable', 'uuid'],
            'contains_personal_data' => ['sometimes', 'boolean'],
        ], ['programme_id.required' => 'Choose the programme this form is for.', 'programme_id.in' => 'Choose one of your programmes.']);

        if (($data['programme_unit_id'] ?? null) !== null && ($data['programme_id'] ?? null) !== null) {
            $programme = $access->programme($data['programme_id']);
            abort_unless($access->reachesUnit($programme, $data['programme_unit_id']), 422, 'You do not reach this unit.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOptions(): array
    {
        $access = $this->access();
        $programmes = $access->programmes()->whereNull('archived_at')->with('units')->orderBy('code')->get();

        return [
            'programmes' => $programmes->map(fn (Programme $programme) => [
                'value' => $programme->id,
                'label' => $programme->label(),
                'units' => $programme->units
                    ->filter(fn (ProgrammeUnit $unit) => $unit->archived_at === null && $access->reachesUnit($programme, $unit->id))
                    ->map(fn (ProgrammeUnit $unit) => ['value' => $unit->id, 'label' => $unit->label()])->values(),
            ])->values(),
            'purposes' => self::options(Form::PURPOSES, fn (string $purpose) => ucfirst(str_replace('_', ' ', $purpose))),
            'programmeRequired' => ! $access->reachesEveryProgramme(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function programmeOptions(): array
    {
        return array_values($this->access()->programmes()->orderBy('code')->get()
            ->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()])->all());
    }
}
