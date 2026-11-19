<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Logbooks;
use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LogbookController extends Controller
{
    public function index(Request $request): Response
    {
        $archived = $request->boolean('archived');
        $logbooks = Access::logbooks()->where('status', $archived ? 'archived' : 'active')->orderBy('name')->get();
        $sheetCounts = Access::sheets()->whereIn('logbook_id', $logbooks->pluck('id'))->where('status', '!=', 'archived')
            ->toBase()->selectRaw('logbook_id, count(*) as n')->groupBy('logbook_id')->pluck('n', 'logbook_id');
        $entryCounts = Access::entries()->where('status', '!=', 'draft')->whereIn('logbook_id', $logbooks->pluck('id'))
            ->toBase()->selectRaw('logbook_id, count(*) as n')->groupBy('logbook_id')->pluck('n', 'logbook_id');

        return Inertia::render('logbooks/index', [
            'archived' => $archived,
            'logbooks' => $logbooks->map(fn (Logbook $l) => [
                'id' => $l->id, 'name' => $l->name, 'description' => $l->description, 'icon' => $l->icon,
                'restricted' => $l->restricted, 'status' => $l->status,
                'sheets' => (int) ($sheetCounts[$l->id] ?? 0), 'entries' => (int) ($entryCounts[$l->id] ?? 0),
            ]),
            'icons' => Logbook::ICONS,
            'canManage' => Access::manages(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $logbook = Logbooks::create($this->validated($request), $this->user());

        return to_route('logbooks.show', $logbook->id)->with('success', 'Logbook created. Add its first log sheet.');
    }

    public function show(string $logbook): Response
    {
        $logbook = Access::logbook($logbook);
        $manages = Access::manages();
        $sheets = Access::sheets()->where('logbook_id', $logbook->id)->with('draftVersion:id,log_sheet_id,version', 'publishedVersion:id,version')
            ->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")->orderBy('name')->get();
        $counts = Access::entries()->where('status', '!=', 'draft')->where('logbook_id', $logbook->id)
            ->toBase()->selectRaw('log_sheet_id, count(*) as n, max(occurred_at) as last')->groupBy('log_sheet_id')->get()->keyBy('log_sheet_id');
        $writable = Access::writableSheets()->where('logbook_id', $logbook->id)->pluck('id')->all();

        return Inertia::render('logbooks/show', [
            'logbook' => [
                'id' => $logbook->id, 'name' => $logbook->name, 'description' => $logbook->description, 'icon' => $logbook->icon,
                'restricted' => $logbook->restricted, 'status' => $logbook->status, 'createdAt' => self::time($logbook->created_at),
                'creator' => $logbook->creator->name,
            ],
            'sheets' => $sheets->map(fn (LogSheet $s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'description' => $s->description, 'status' => $s->status,
                'reviewRequired' => $s->review_required, 'version' => $s->publishedVersion?->version, 'hasDraft' => $s->draftVersion !== null,
                'entries' => (int) ($counts[$s->id]->n ?? 0), 'lastEntryAt' => isset($counts[$s->id]) ? $counts[$s->id]->last : null,
                'canWrite' => in_array($s->id, $writable, true),
            ]),
            // Not 'access': the shared prop of that name holds the member's own permissions.
            'grants' => $manages ? $logbook->grants()->with('user:id,name,email', 'sheet:id,code,name')->get()
                ->sortBy(fn (AccessGrant $g) => $g->user->name)->values()
                ->map(fn (AccessGrant $g) => [
                    'id' => $g->id, 'user' => $g->user->name, 'email' => $g->user->email, 'role' => $g->role,
                    'sheet' => $g->sheet ? "{$g->sheet->code} · {$g->sheet->name}" : null,
                ]) : null,
            'members' => $manages ? WorkspaceMember::options() : [],
            'icons' => Logbook::ICONS,
            'canManage' => $manages,
            'hasEntries' => Entry::query()->where('logbook_id', $logbook->id)->exists(),
        ]);
    }

    public function update(Request $request, string $logbook): RedirectResponse
    {
        Logbooks::update(Logbook::query()->findOrFail($logbook), $this->validated($request), $this->user());

        return back()->with('success', 'Logbook saved.');
    }

    public function archive(string $logbook): RedirectResponse
    {
        Logbooks::archive(Logbook::query()->findOrFail($logbook), $this->user());

        return back()->with('success', 'Logbook archived. Its records are kept and stay readable; no new entries can be written.');
    }

    public function restore(string $logbook): RedirectResponse
    {
        Logbooks::restore(Logbook::query()->findOrFail($logbook), $this->user());

        return back()->with('success', 'Logbook restored.');
    }

    public function grant(Request $request, string $logbook): RedirectResponse
    {
        $logbook = Logbook::query()->findOrFail($logbook);
        $data = $request->validate([
            'user_id' => ['required', 'uuid', WorkspaceMember::rule()],
            'role' => ['required', Rule::in(AccessGrant::ROLES)],
            'log_sheet_id' => ['nullable', 'uuid'],
        ]);
        $sheet = isset($data['log_sheet_id']) ? LogSheet::query()->whereKey((string) $data['log_sheet_id'])->firstOrFail() : null;
        Logbooks::grant($logbook, $data['user_id'], $data['role'], $sheet, $this->user());

        return back()->with('success', 'Access saved. It applies with the member\'s Logbook role from Tatook.');
    }

    public function revoke(string $grant): RedirectResponse
    {
        Logbooks::revoke(AccessGrant::query()->findOrFail($grant), $this->user());

        return back()->with('success', 'Access removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        abort_unless(Gate::allows('logbook.manage'), 403);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', Rule::in(Logbook::ICONS)],
            'restricted' => ['boolean'],
        ]);
    }
}
