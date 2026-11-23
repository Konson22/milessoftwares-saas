<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\Shift;
use App\Logbook\Shifts;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational shifts (ADR-0030 §4.5): read by everyone, kept by managers.
 * A shift used by entries is never deleted, only switched off, so the
 * entries keep the shift they were recorded in.
 */
class ShiftController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shifts/index', [
            'shifts' => Shift::query()->with(['logbook:id,name', 'members:id,name'])->orderByDesc('active')->orderBy('starts_time')->get()
                ->map(fn (Shift $s) => [
                    'id' => $s->id, 'name' => $s->name, 'starts' => $s->starts_time, 'ends' => $s->ends_time,
                    'overnight' => $s->overnight(), 'active' => $s->active,
                    'logbook' => $s->logbook ? ['id' => $s->logbook->id, 'name' => $s->logbook->name] : null,
                    'members' => $s->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
                    'entries' => Entry::query()->where('shift_id', $s->id)->count(),
                ])->values(),
            'logbooks' => Access::logbooks()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Logbook $l) => ['value' => $l->id, 'label' => $l->name])->values(),
            'members' => WorkspaceMember::options(),
            'canManage' => Gate::allows('logbook.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = Shifts::save(null, $this->input($request), $this->user());

        return back()->with('success', "{$shift->name} shift added.");
    }

    public function update(Request $request, string $shift): RedirectResponse
    {
        $shift = Shifts::save(Shift::query()->findOrFail($shift), $this->input($request), $this->user());

        return back()->with('success', "{$shift->name} shift saved.");
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_time' => ['required', 'string'],
            'ends_time' => ['required', 'string'],
            'logbook_id' => ['nullable', 'uuid'],
            'active' => ['boolean'],
            'member_ids' => ['nullable', 'array', 'max:500'],
            'member_ids.*' => ['uuid'],
        ]);
    }
}
