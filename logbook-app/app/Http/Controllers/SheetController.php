<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Assignments;
use App\Logbook\Fields;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Recording;
use App\Logbook\References;
use App\Logbook\ResourceCategories;
use App\Logbook\SheetAssets;
use App\Logbook\Sheets;
use App\Logbook\StarterTemplates;
use App\Logbook\Stops;
use App\Logbook\Tracks;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

class SheetController extends Controller
{
    /** A sheet: its instructions, latest entries and versions. */
    public function show(string $sheet): Response
    {
        $sheet = Access::sheet($sheet);
        $entries = Access::entries()->where('log_sheet_id', $sheet->id)->where('status', '!=', 'draft')
            ->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields'])
            ->latest('occurred_at')->limit(25)->get();

        return Inertia::render('sheets/show', [
            'sheet' => $this->sheetData($sheet),
            'entries' => $entries->map(fn (Entry $e) => self::entryRow($e)),
            'versions' => $sheet->versions()->get()->map(fn (LogSheetVersion $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'fields' => count($v->fields),
                'publishedAt' => self::time($v->published_at),
            ]),
            'fields' => collect($sheet->publishedVersion->fields ?? [])->map(fn (array $f) => [
                'name' => $f['name'], 'label' => $f['label'], 'type' => Fields::TYPES[$f['type']] ?? $f['type'], 'required' => ($f['required'] ?? false) === true,
                'unit' => $f['unit'] ?? null, 'range' => isset($f['expected_min']) || isset($f['expected_max']) ? [($f['expected_min'] ?? null), ($f['expected_max'] ?? null)] : null,
            ])->values(),
            'stats' => self::sheetStats($sheet),
            'recordingMode' => Recording::mode($sheet),
            ...self::assetLinks($sheet),
            'canWrite' => Access::writableSheets()->whereKey($sheet->id)->exists(),
            'canManage' => Access::managedLogbooks()->whereKey($sheet->logbook_id)->exists(),
            'logbooks' => Access::manages() ? Access::managedLogbooks()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Logbook $l) => ['value' => $l->id, 'label' => $l->name]) : [],
        ]);
    }

    /**
     * The assets the sheet records (ADR-0031 §6), and those a manager may
     * still link: active, of the main field's type.
     *
     * @return array<string, mixed>
     */
    private static function assetLinks(LogSheet $sheet): array
    {
        $field = SheetAssets::subjectFieldOf($sheet);
        $linked = $sheet->resources()->with('location.parent')->orderBy('code')->get();
        $canManage = Access::managedLogbooks()->whereKey($sheet->logbook_id)->exists();
        $type = is_string($field['resource_type'] ?? null) && $field['resource_type'] !== '' ? $field['resource_type'] : null;

        return [
            'assetField' => $field === null ? null : ['label' => $field['label'], 'type' => $field['resource_type'] ?? null],
            'assets' => $linked->map(fn (LogResource $r) => ['id' => $r->id, 'label' => $r->label(), 'type' => $r->type, 'status' => $r->status, 'location' => $r->location?->path()])->values(),
            'assetOptions' => ! $canManage || $field === null ? [] : ResourceCategories::matching(LogResource::query()->where('status', 'active')->whereNotIn('id', $linked->pluck('id')), $type)->orderBy('code')->get()
                ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->values(),
        ];
    }

    /** The template builder: the draft (or a copy of the published version) and a preview. */
    public function template(string $sheet): Response
    {
        $sheet = Access::managedSheet($sheet);
        $draft = $sheet->draftVersion;

        return Inertia::render('sheets/template', [
            'sheet' => $this->sheetData($sheet),
            'fields' => $draft->fields ?? $sheet->publishedVersion->fields ?? [],
            // GPS (ADR-0028): part of the version, so it is published with the fields.
            'location' => Tracks::settings($draft ?? $sheet->publishedVersion),
            // How the sheet is recorded (ADR-0030): also part of the version.
            'recording' => ['mode' => Recording::mode($sheet, $draft ?? $sheet->publishedVersion), 'shifts' => Recording::settings($draft ?? $sheet->publishedVersion)['shifts'] ?? 'off', 'activity' => Recording::activity($draft ?? $sheet->publishedVersion), 'subject' => ($draft ?? $sheet->publishedVersion)?->recording['subject'] ?? null, 'assignedOnly' => Assignments::required($draft ?? $sheet->publishedVersion), 'stops' => Stops::setting($draft ?? $sheet->publishedVersion), 'liveSchedules' => Recording::hasLiveSchedules($sheet)],
            'draftVersion' => $draft?->version,
            'publishedVersion' => $sheet->publishedVersion?->version,
            'types' => collect(Fields::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'settings' => Fields::SETTINGS[$value]])->values(),
            'members' => WorkspaceMember::options(),
            'resources' => self::resourceOptions(),
            'history' => AuditEvent::query()->where('subject_type', 'log_sheet')->where('subject_id', $sheet->id)
                ->latest('created_at')->limit(20)->get()
                ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'details' => $e->details, 'at' => self::time($e->created_at)]),
        ]);
    }

    public function store(Request $request, string $logbook): RedirectResponse
    {
        $logbook = Access::managedLogbook($logbook);
        $sheet = Sheets::create($logbook, $this->settings($request), [], $this->user());

        return to_route('sheets.template', $sheet->id)->with('success', 'Log sheet created. Add its fields, then publish.');
    }

    public function starters(string $logbook): Response
    {
        $logbook = Access::managedLogbook($logbook);

        return Inertia::render('sheets/starters', [
            'logbook' => ['id' => $logbook->id, 'name' => $logbook->name],
            'templates' => collect(StarterTemplates::all())->map(fn ($t, $key) => [
                'key' => $key, 'name' => $t['name'], 'code' => $t['code'], 'description' => $t['description'],
                'reviewRequired' => $t['review_required'],
                'mode' => $t['recording']['mode'] ?? 'on_demand',
                'fuel' => isset($t['recording']['activity']),
                'gps' => isset($t['location']),
                'fields' => array_map(fn ($f) => [
                    'label' => $f['label'], 'kind' => $f['type'], 'type' => Fields::TYPES[$f['type']], 'required' => $f['required'],
                    'choices' => array_column($f['choices'] ?? [], 'label'),
                ], $t['fields']),
            ])->values(),
        ]);
    }

    public function fromStarter(Request $request, string $logbook): RedirectResponse
    {
        $logbook = Access::managedLogbook($logbook);
        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(StarterTemplates::all()))],
            ...$this->settingsRules(),
        ]);
        $template = StarterTemplates::all()[$data['template']];
        $sheet = Sheets::create($logbook, [
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $template['description'],
            'review_required' => $template['review_required'],
        ], $template['fields'], $this->user(), 'starter '.$data['template']);
        // ADR-0031: how the starter suggests recording it comes along, as a draft to change.
        if (isset($template['recording']) || isset($template['location'])) {
            Sheets::saveDraft($sheet, $template['fields'], $this->user(), $template['location'] ?? null, isset($template['location']), $template['recording'] ?? null, isset($template['recording']));
        }

        return to_route('sheets.template', $sheet->id)->with('success', 'Copied from the starter template. Change anything, then publish.');
    }

    public function update(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        Sheets::updateSettings($sheet, $this->settings($request, $sheet), $this->user());

        return back()->with('success', 'Log sheet saved.');
    }

    public function saveDraft(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        $request->validate(['fields' => ['present', 'array', 'max:'.Fields::MAX_FIELDS], 'location' => ['nullable', 'array'], 'recording' => ['nullable', 'array']]);
        Sheets::saveDraft($sheet, $request->input('fields'), $this->user(), $request->input('location'), $request->has('location'), $request->input('recording'), $request->has('recording'));

        return back()->with('success', 'Draft saved. Publish it when it is ready.');
    }

    public function publish(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        if ($request->has('fields')) {
            Sheets::saveDraft($sheet, $request->input('fields'), $this->user(), $request->input('location'), $request->has('location'), $request->input('recording'), $request->has('recording'));
        }
        $version = Sheets::publish($sheet->fresh() ?? $sheet, $this->user());

        return to_route('sheets.show', $sheet->id)->with('success', "Version {$version->version} published. New entries use it; earlier entries keep their own version.");
    }

    public function discardDraft(string $sheet): RedirectResponse
    {
        Sheets::discardDraft(Access::managedSheet($sheet), $this->user());

        return to_route('sheets.show', $sheet)->with('success', 'Draft discarded.');
    }

    public function duplicate(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        $data = $request->validate([...$this->settingsRules(), 'logbook_id' => ['required', 'uuid']]);
        $copy = Sheets::duplicate($sheet, Access::managedLogbook((string) $data['logbook_id']), $data['name'], $data['code'], $this->user());

        return to_route('sheets.template', $copy->id)->with('success', 'Copy created as a draft.');
    }

    public function archive(string $sheet): RedirectResponse
    {
        Sheets::archive(Access::managedSheet($sheet), $this->user());

        return back()->with('success', 'Log sheet archived. Its entries are kept and stay readable.');
    }

    public function restore(string $sheet): RedirectResponse
    {
        Sheets::restore(Access::managedSheet($sheet), $this->user());

        return back()->with('success', 'Log sheet restored.');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Activity on one sheet, within what the viewer reads.
     *
     * @return array<string, mixed>
     */
    private static function sheetStats(LogSheet $sheet): array
    {
        $entries = fn () => Access::entries()->where('log_sheet_id', $sheet->id)->where('status', '!=', 'draft');
        $open = fn () => LogException::query()->where('log_sheet_id', $sheet->id)->whereIn('status', LogException::OPEN);

        return [
            'total' => $entries()->count(),
            'lastWeek' => $entries()->where('occurred_at', '>=', now()->subDays(7))->count(),
            'awaiting' => $entries()->where('status', 'submitted')->count(),
            'returned' => $entries()->where('status', 'returned')->count(),
            'lastEntryAt' => self::time($entries()->latest('occurred_at')->first(['id', 'occurred_at'])?->occurred_at),
            'openExceptions' => $open()->count(),
            'seriousExceptions' => $open()->whereIn('severity', ['high', 'critical'])->count(),
        ];
    }

    private function sheetData(LogSheet $sheet): array
    {
        return [
            'id' => $sheet->id, 'name' => $sheet->name, 'code' => $sheet->code, 'description' => $sheet->description,
            'instructions' => $sheet->instructions, 'status' => $sheet->status, 'reviewRequired' => $sheet->review_required,
            'version' => $sheet->publishedVersion?->version, 'hasDraft' => $sheet->draftVersion()->exists(),
            'logbook' => ['id' => $sheet->logbook_id, 'name' => $sheet->logbook->name, 'status' => $sheet->logbook->status],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsRules(?LogSheet $sheet = null): array
    {
        // Codes are kept uppercase: "gen" and "GEN" are the same code.
        if (is_string(request()->input('code'))) {
            request()->merge(['code' => strtoupper(trim(request()->input('code')))]);
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/', Rule::notIn(References::RESERVED_CODES),
                Rule::unique('log_sheets', 'code')->where('organization_id', $this->organizationId())->ignore($sheet?->id), ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Request $request, ?LogSheet $sheet = null): array
    {
        return $request->validate([
            ...$this->settingsRules($sheet),
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'review_required' => ['boolean'],
        ], ['code.regex' => 'Two to ten letters or digits, starting with a letter (it begins every reference).', 'code.unique' => 'Another log sheet uses this code.', 'code.not_in' => 'TASK and EXC begin task and exception references: choose another code.']);
    }

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
