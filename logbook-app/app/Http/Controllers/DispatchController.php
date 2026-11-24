<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Assignments;
use App\Logbook\Dispatches;
use App\Logbook\Models\Dispatch;
use App\Logbook\Models\LogSheet;
use App\Logbook\Recording;
use App\Logbook\SheetAssets;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planned trips (ADR-0032 §4). Managers plan and cancel them on the sheets
 * of the logbooks they manage; a driver sees their own and starts them.
 */
class DispatchController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Dispatch::STATUSES)],
            'day' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $me = $this->user();
        $manages = Access::manages();
        $from = CarbonImmutable::parse(Assignments::today())->subDays(7)->toDateString();

        $query = Dispatch::query()->with(['resource:id,code,name', 'sheet:id,name,code', 'user:id,name'])
            ->when($manages,
                fn ($q) => $q->where(fn ($w) => $w->whereIn('logbook_id', Access::managedLogbooks()->select('id'))->orWhere('user_id', $me->id)),
                fn ($q) => $q->where('user_id', $me->id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['day'] ?? null, fn ($q, $d) => $q->whereDate('planned_on', $d), fn ($q) => $q->whereDate('planned_on', '>=', $from))
            ->orderByRaw("case status when 'started' then 0 when 'planned' then 1 else 2 end")
            ->orderBy('planned_on')->orderBy('planned_time');

        return Inertia::render('trips/index', [
            'trips' => $query->limit(200)->get()->map(fn (Dispatch $d) => [
                ...Dispatches::row($d),
                'driver' => $d->user->name, 'mine' => $d->user_id === $me->id,
                'cancelReason' => $d->cancel_reason,
                'canCancel' => $d->status === 'planned' && $manages && Access::managedLogbooks()->whereKey($d->logbook_id)->exists(),
            ])->values(),
            'filters' => $filters,
            'labels' => Dispatches::LABELS,
            'today' => Assignments::today(),
            'canPlan' => $manages,
            // What a manager plans with: sheets naming a vehicle, the vehicles each may take, the people.
            'sheets' => $manages ? self::plannable() : [],
            'resources' => $manages ? self::resourceOptions() : [],
            'members' => $manages ? WorkspaceMember::options() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sheet_id' => ['required', 'uuid'],
            'resource_id' => ['required', 'string'],
            'user_id' => ['required', 'string'],
            'planned_on' => ['required', 'string'],
            'planned_time' => ['nullable', 'string'],
            'destination' => ['nullable', 'string', 'max:200'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ]);
        $sheet = Access::managedSheet($data['sheet_id']);
        $dispatch = Dispatches::plan($sheet, $data, $this->user());

        return back()->with('success', 'Trip planned for '.$dispatch->user->name.'.');
    }

    public function cancel(Request $request, string $dispatch): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $dispatch = Dispatch::query()->whereIn('logbook_id', Access::managedLogbooks()->select('id'))->findOrFail($dispatch);
        Dispatches::cancel($dispatch, $reason, $this->user());

        return back()->with('success', 'Trip cancelled.');
    }

    /**
     * Published sheets the manager manages that name an asset, with the
     * asset type they take and their linked assets ([] = any of the type).
     *
     * @return list<array{value: string, label: string, resourceType: string|null, assets: list<string>}>
     */
    private static function plannable(): array
    {
        return array_values(LogSheet::query()->whereIn('logbook_id', Access::managedLogbooks()->select('id'))
            ->where('status', '!=', 'archived')->whereNotNull('published_version_id')->with('publishedVersion')->orderBy('name')->get()
            ->map(function (LogSheet $s) {
                $subject = Recording::subjectField($s->publishedVersion);
                if ($subject === null) {
                    return null;
                }
                $field = collect($s->publishedVersion->fields)->firstWhere('name', $subject);

                return ['value' => $s->id, 'label' => "{$s->code} · {$s->name}", 'resourceType' => $field['resource_type'] ?? null, 'assets' => SheetAssets::linkedIds($s->id)];
            })->filter()->values()->all());
    }
}
