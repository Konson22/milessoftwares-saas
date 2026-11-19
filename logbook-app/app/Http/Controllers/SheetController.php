<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Fields;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Sheets;
use App\Logbook\StarterTemplates;
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
            'canWrite' => Access::writableSheets()->whereKey($sheet->id)->exists(),
            'canManage' => Access::manages(),
            'logbooks' => Access::manages() ? Logbook::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Logbook $l) => ['value' => $l->id, 'label' => $l->name]) : [],
        ]);
    }

    /** The template builder: the draft (or a copy of the published version) and a preview. */
    public function template(string $sheet): Response
    {
        $sheet = LogSheet::query()->findOrFail($sheet);
        $draft = $sheet->draftVersion;

        return Inertia::render('sheets/template', [
            'sheet' => $this->sheetData($sheet),
            'fields' => $draft->fields ?? $sheet->publishedVersion->fields ?? [],
            'draftVersion' => $draft?->version,
            'publishedVersion' => $sheet->publishedVersion?->version,
            'types' => collect(Fields::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'settings' => Fields::SETTINGS[$value]])->values(),
            'members' => WorkspaceMember::options(),
            'history' => AuditEvent::query()->where('subject_type', 'log_sheet')->where('subject_id', $sheet->id)
                ->latest('created_at')->limit(20)->get()
                ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'details' => $e->details, 'at' => self::time($e->created_at)]),
        ]);
    }

    public function store(Request $request, string $logbook): RedirectResponse
    {
        $logbook = Logbook::query()->findOrFail($logbook);
        $sheet = Sheets::create($logbook, $this->settings($request), [], $this->user());

        return to_route('sheets.template', $sheet->id)->with('success', 'Log sheet created. Add its fields, then publish.');
    }

    public function starters(string $logbook): Response
    {
        $logbook = Logbook::query()->findOrFail($logbook);

        return Inertia::render('sheets/starters', [
            'logbook' => ['id' => $logbook->id, 'name' => $logbook->name],
            'templates' => collect(StarterTemplates::all())->map(fn ($t, $key) => [
                'key' => $key, 'name' => $t['name'], 'code' => $t['code'], 'description' => $t['description'],
                'reviewRequired' => $t['review_required'],
                'fields' => array_map(fn ($f) => [
                    'label' => $f['label'], 'kind' => $f['type'], 'type' => Fields::TYPES[$f['type']], 'required' => $f['required'],
                    'choices' => array_column($f['choices'] ?? [], 'label'),
                ], $t['fields']),
            ])->values(),
        ]);
    }

    public function fromStarter(Request $request, string $logbook): RedirectResponse
    {
        $logbook = Logbook::query()->findOrFail($logbook);
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

        return to_route('sheets.template', $sheet->id)->with('success', 'Copied from the starter template. Change anything, then publish.');
    }

    public function update(Request $request, string $sheet): RedirectResponse
    {
        $sheet = LogSheet::query()->findOrFail($sheet);
        Sheets::updateSettings($sheet, $this->settings($request, $sheet), $this->user());

        return back()->with('success', 'Log sheet saved.');
    }

    public function saveDraft(Request $request, string $sheet): RedirectResponse
    {
        $sheet = LogSheet::query()->findOrFail($sheet);
        $request->validate(['fields' => ['present', 'array', 'max:'.Fields::MAX_FIELDS]]);
        Sheets::saveDraft($sheet, $request->input('fields'), $this->user());

        return back()->with('success', 'Draft saved. Publish it when it is ready.');
    }

    public function publish(Request $request, string $sheet): RedirectResponse
    {
        $sheet = LogSheet::query()->findOrFail($sheet);
        if ($request->has('fields')) {
            Sheets::saveDraft($sheet, $request->input('fields'), $this->user());
        }
        $version = Sheets::publish($sheet->fresh() ?? $sheet, $this->user());

        return to_route('sheets.show', $sheet->id)->with('success', "Version {$version->version} published. New entries use it; earlier entries keep their own version.");
    }

    public function discardDraft(string $sheet): RedirectResponse
    {
        Sheets::discardDraft(LogSheet::query()->findOrFail($sheet), $this->user());

        return to_route('sheets.show', $sheet)->with('success', 'Draft discarded.');
    }

    public function duplicate(Request $request, string $sheet): RedirectResponse
    {
        $sheet = LogSheet::query()->findOrFail($sheet);
        $data = $request->validate([...$this->settingsRules(), 'logbook_id' => ['required', 'uuid']]);
        $copy = Sheets::duplicate($sheet, Logbook::query()->whereKey((string) $data['logbook_id'])->firstOrFail(), $data['name'], $data['code'], $this->user());

        return to_route('sheets.template', $copy->id)->with('success', 'Copy created as a draft.');
    }

    public function archive(string $sheet): RedirectResponse
    {
        Sheets::archive(LogSheet::query()->findOrFail($sheet), $this->user());

        return back()->with('success', 'Log sheet archived. Its entries are kept and stay readable.');
    }

    public function restore(string $sheet): RedirectResponse
    {
        Sheets::restore(LogSheet::query()->findOrFail($sheet), $this->user());

        return back()->with('success', 'Log sheet restored.');
    }

    /**
     * @return array<string, mixed>
     */
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
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/',
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
        ], ['code.regex' => 'Two to ten letters or digits, starting with a letter (it begins every reference).', 'code.unique' => 'Another log sheet uses this code.']);
    }

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
