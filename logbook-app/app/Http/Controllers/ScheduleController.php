<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsWork;
use App\Logbook\Access;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Recording;
use App\Logbook\Reminders;
use App\Logbook\ResourceCategories;
use App\Logbook\Schedules;
use App\Logbook\SheetAssets;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scheduled log sheets (ADR-0026 §2.6): the dashboard of expected,
 * completed, late and missed entries; schedules set on a sheet; excusing
 * an occurrence.
 */
class ScheduleController extends Controller
{
    use PresentsWork;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'logbook_id' => ['nullable', 'uuid'],
            'log_sheet_id' => ['nullable', 'uuid'],
            'responsible_id' => ['nullable', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'state' => ['nullable', Rule::in(['due', 'overdue', 'upcoming', 'awaiting_review', 'completed', 'late', 'missed', 'excused'])],
        ]);
        $tz = Workspace::current()->timezone ?? 'UTC';
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::parse($data['from'] ?? $now->setTimezone($tz)->subDays(6)->toDateString(), $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $now->setTimezone($tz)->addDay()->toDateString(), $tz)->endOfDay();

        $query = fn () => Schedules::visible()
            ->whereBetween('due_at', [$from->utc(), $to->utc()])
            ->when($data['logbook_id'] ?? null, fn (Builder $q, $id) => $q->where('logbook_id', $id))
            ->when($data['log_sheet_id'] ?? null, fn (Builder $q, $id) => $q->where('log_sheet_id', $id))
            ->when($data['responsible_id'] ?? null, fn (Builder $q, $id) => $q->where('responsible_id', $id))
            ->when($data['resource_id'] ?? null, fn (Builder $q, $id) => $q->where('resource_id', $id));

        $rows = $query()->with(['sheet:id,name,code', 'logbook:id,name', 'responsible:id,name', 'entry:id,reference', 'resource:id,code,name'])
            ->orderByDesc('due_at')->limit(500)->get()
            ->map(fn (ScheduleOccurrence $o) => self::occurrenceRow($o, $now))
            ->when($data['state'] ?? null, fn ($c, $state) => $c->where('state', $state))
            ->values();

        $outstandingReviews = Access::reviewable()->whereIn('id', $query()->whereNotNull('log_entry_id')->select('log_entry_id'))->count();

        return Inertia::render('schedules/index', [
            'filters' => [
                'from' => $from->toDateString(), 'to' => $to->setTimezone($tz)->toDateString(),
                'logbook_id' => $data['logbook_id'] ?? '', 'log_sheet_id' => $data['log_sheet_id'] ?? '',
                'responsible_id' => $data['responsible_id'] ?? '', 'resource_id' => $data['resource_id'] ?? '', 'state' => $data['state'] ?? '',
            ],
            'figures' => [...Schedules::figures($query(), $now), 'outstandingReviews' => $outstandingReviews],
            'occurrences' => $rows,
            'schedules' => Access::limit(Schedule::query(), array_keys(Access::CAPABILITIES))->with(['sheet:id,name,code', 'responsible:id,name', 'resource:id,code,name'])
                ->orderByDesc('active')->get()->map(fn (Schedule $s) => self::scheduleRow($s))->values(),
            'options' => [
                ...self::filterOptions(),
                'members' => WorkspaceMember::options(),
                // ADR-0030: only scheduled sheets (or sheets from before recording modes) take a new schedule.
                // ADR-0031: assets a schedule may be for, by sheet (linked ones, or any of the main field's type).
                'sheetAssets' => self::sheetAssets(),
                'assets' => LogResource::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->values(),
                'schedulable' => Access::sheets()->whereNotNull('published_version_id')->with(['publishedVersion', 'draftVersion'])->get()
                    ->filter(fn (LogSheet $s) => Recording::allowsSchedules($s))->pluck('id')->values(),
            ],
            'canManage' => Access::manages(),
        ]);
    }

    public function store(Request $request, string $sheet): RedirectResponse
    {
        $sheet = Access::managedSheet($sheet);
        $schedule = Schedules::save($sheet, null, $this->input($request), $this->user());

        return back()->with('success', "{$sheet->code} scheduled. Expected entries appear a day ahead.")->with('schedule', $schedule->id);
    }

    public function update(Request $request, string $schedule): RedirectResponse
    {
        $schedule = $this->find($schedule);
        $sheet = Access::managedSheet($schedule->log_sheet_id);
        Schedules::save($sheet, $schedule, $this->input($request), $this->user());

        return back()->with('success', 'Schedule saved.');
    }

    public function pause(string $schedule): RedirectResponse
    {
        Schedules::pause($this->find($schedule), $this->user());

        return back()->with('success', 'Schedule paused. No entries are expected until you resume it.');
    }

    public function resume(string $schedule): RedirectResponse
    {
        Schedules::resume($this->find($schedule), $this->user());

        return back()->with('success', 'Schedule resumed. Entries are expected again from now on.');
    }

    public function end(string $schedule): RedirectResponse
    {
        Schedules::end($this->find($schedule), $this->user());

        return back()->with('success', 'Schedule ended. Its history stays.');
    }

    /**
     * For each schedulable sheet that names an asset: the assets a
     * schedule may be for.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private static function sheetAssets(): array
    {
        if (! Access::manages()) {
            return [];
        }
        $active = LogResource::query()->where('status', 'active')->orderBy('code')->get();
        $out = [];
        foreach (Access::managedLogbooks()->get() as $logbook) {
            foreach (LogSheet::query()->where('logbook_id', $logbook->id)->whereNotNull('published_version_id')->with(['publishedVersion', 'draftVersion'])->get() as $sheet) {
                $field = SheetAssets::subjectFieldOf($sheet);
                if ($field === null) {
                    continue;
                }
                $linked = SheetAssets::linkedIds($sheet->id);
                $type = is_string($field['resource_type'] ?? null) ? $field['resource_type'] : null;
                $out[$sheet->id] = $active->filter(fn (LogResource $r) => $linked !== [] ? in_array($r->id, $linked, true) : ResourceCategories::matches($r, $type))
                    ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->values()->all();
            }
        }

        return $out;
    }

    /** A schedule on a sheet of a logbook the member manages. */
    private function find(string $schedule): Schedule
    {
        return Schedule::query()->whereIn('logbook_id', Access::managedLogbooks()->select('id'))->findOrFail($schedule);
    }

    public function excuse(Request $request, string $occurrence): RedirectResponse
    {
        $occurrence = Schedules::visible()->whereIn('logbook_id', Access::managedLogbooks()->select('id'))->findOrFail($occurrence);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Schedules::excuse($occurrence, $this->user(), $data['reason']);

        return back()->with('success', 'Occurrence excused.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function scheduleRow(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'sheet' => ['id' => $schedule->sheet->id, 'name' => $schedule->sheet->name, 'code' => $schedule->sheet->code],
            // ADR-0031: the asset whose readings it expects; null: the whole sheet.
            'resource' => $schedule->resource !== null ? ['id' => $schedule->resource->id, 'label' => $schedule->resource->label()] : null,
            'frequency' => $schedule->frequency,
            'weekdays' => $schedule->weekdays ?? [],
            'dayOfMonth' => $schedule->day_of_month,
            'opensTime' => $schedule->opens_time,
            'dueTime' => $schedule->due_time,
            'times' => Schedules::times($schedule),
            'lateMinutes' => $schedule->late_minutes,
            'timezone' => $schedule->timezone,
            'startsOn' => $schedule->starts_on->toDateString(),
            'endsOn' => $schedule->ends_on?->toDateString(),
            'responsible' => $schedule->responsible !== null ? ['id' => $schedule->responsible->id, 'name' => $schedule->responsible->name] : null,
            'completion' => $schedule->completion,
            'reminders' => $schedule->reminders ?? [],
            'reminderChannels' => $schedule->reminder_channels,
            'active' => $schedule->active,
            'state' => Schedules::state($schedule),
            'pausedAt' => $schedule->paused_at?->toIso8601String(),
            'endedAt' => $schedule->ended_at?->toIso8601String(),
        ];
    }

    /**
     * Reminder presets that make sense for an expected entry (no start, no fixed moment).
     *
     * @return list<string>
     */
    public static function reminderPresets(): array
    {
        return array_values(array_filter(Reminders::PRESETS, fn (string $t) => $t !== 'start'));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'opens_time' => ['required_without:times', 'nullable', 'string'],
            'due_time' => ['required_without:times', 'nullable', 'string'],
            // ADR-0030: several readings a day.
            'times' => ['nullable', 'array', 'max:24'],
            'times.*.opens' => ['required', 'string'],
            'times.*.due' => ['required', 'string'],
            'late_minutes' => ['required', 'integer', 'between:0,10080'],
            'timezone' => ['required', 'string'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'completion' => ['required', Rule::in(['submitted', 'reviewed'])],
            'reminders' => ['nullable', 'array', 'max:6'],
            'reminders.*' => ['string', 'max:40'],
            'reminder_channels' => ['nullable', 'array'],
            'reminder_channels.*' => [Rule::in(Reminders::CHANNELS)],
            'active' => ['boolean'],
        ]);
    }
}
