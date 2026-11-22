<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsWork;
use App\Logbook\Access;
use App\Logbook\Exceptions;
use App\Logbook\Fields;
use App\Logbook\Member;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use App\Logbook\Schedules;
use App\Logbook\Tasks;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overview: figures over what the member reaches, what waits on them,
 * and the sheets they write on most.
 */
class HomeController extends Controller
{
    use PresentsWork;

    public function __invoke(): Response
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay()->utc();
        $readable = fn () => Access::entries()->where('status', '!=', 'draft');
        $me = $this->user()->id;

        $frequent = Entry::query()->where('created_by', $me)->where('created_at', '>=', now()->subDays(60))
            ->toBase()->selectRaw('log_sheet_id, count(*) as n')->groupBy('log_sheet_id')->orderByDesc('n')->limit(6)->pluck('n', 'log_sheet_id');
        $writable = Access::writableSheets()->whereIn('id', $frequent->keys())->with('logbook:id,name')->get()->keyBy('id');

        $recentLogbookIds = Access::logbooks()->pluck('id');
        $seesAudit = Gate::allows('logbook.audit.view');

        $member = Member::current();
        $nowAt = CarbonImmutable::now();
        $operations = [
            'myTasks' => Tasks::visible($member)->where('assignee_id', $member->id)->whereIn('status', [...Task::OPEN, 'awaiting_verification'])
                ->with(['assignee:id,name', 'creator:id,name', 'logbook:id,name', 'sheet:id,name,code', 'resource:id,code,name'])
                ->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')->limit(6)->get()
                ->map(fn (Task $t) => self::taskRow($t, $nowAt))->values(),
            'overdueTasks' => Tasks::visible($member)->whereIn('status', Task::OPEN)->where('due_at', '<', $nowAt)
                ->when(! $member->can(Tasks::MANAGE), fn ($q) => $q->where('assignee_id', $member->id))->count(),
            'toVerify' => $member->can(Tasks::MANAGE) ? Tasks::visible($member)->where('status', 'awaiting_verification')->where('assignee_id', '!=', $member->id)->count() : null,
            'due' => Schedules::dueFor($member, $nowAt->endOfDay())->with(['sheet:id,name,code', 'logbook:id,name', 'responsible:id,name', 'entry:id,reference'])
                ->limit(8)->get()->map(fn (ScheduleOccurrence $o) => self::occurrenceRow($o, $nowAt))->values(),
            'missedThisWeek' => Schedules::visible($member)->where('status', 'missed')->where('due_at', '>=', $nowAt->subDays(7))->count(),
            'openExceptions' => Exceptions::visible($member)->whereIn('status', LogException::OPEN)->count(),
            'criticalExceptions' => Exceptions::visible($member)->whereIn('status', LogException::OPEN)->whereIn('severity', ['high', 'critical'])->count(),
            // My Work: what comes next from my recurring tasks and the expected entries I let pass.
            'upcoming' => TaskSeries::query()->where('assignee_id', $member->id)->where('active', true)->whereNull('paused_at')->whereNull('ended_at')
                ->get()->map(fn (TaskSeries $s) => ['id' => $s->id, 'title' => $s->title, 'dueAt' => self::moment(Tasks::nextDue($s, $nowAt))])
                ->filter(fn (array $row) => $row['dueAt'] !== null)->sortBy('dueAt')->take(5)->values(),
            'myMissed' => Schedules::visible($member)->where('status', 'missed')->where('due_at', '>=', $nowAt->subDays(7))
                ->where(fn ($q) => $q->whereNull('responsible_id')->orWhere('responsible_id', $member->id))
                ->with(['sheet:id,name,code', 'logbook:id,name', 'responsible:id,name', 'entry:id,reference'])
                ->orderByDesc('due_at')->limit(5)->get()->map(fn (ScheduleOccurrence $o) => self::occurrenceRow($o, $nowAt))->values(),
            'returned' => Entry::query()->where('created_by', $me)->where('status', 'returned')->with('sheet:id,name,code')
                ->orderByDesc('updated_at')->limit(5)->get()
                ->map(fn (Entry $e) => ['id' => $e->id, 'reference' => $e->reference, 'sheet' => $e->sheet->code.' · '.$e->sheet->name, 'at' => self::moment($e->updated_at)])->values(),
        ];

        // The dashboard's week: the last 7 days against the 7 before them.
        $weekFrom = $nowAt->subDays(7);
        $prevFrom = $nowAt->subDays(14);
        $submitted = fn ($from, $to) => $readable()->where('submitted_at', '>=', $from)->where('submitted_at', '<', $to)->count();
        $missed = fn ($from, $to) => Schedules::visible($member)->where('status', 'missed')->where('due_at', '>=', $from)->where('due_at', '<', $to)->count();
        $byLogbook = $readable()->where('submitted_at', '>=', $weekFrom)->toBase()
            ->selectRaw('logbook_id, count(*) as n')->groupBy('logbook_id')->orderByDesc('n')->limit(8)->pluck('n', 'logbook_id');
        $logbookNames = Access::logbooks()->whereIn('id', $byLogbook->keys())->pluck('name', 'id');
        $week = [
            'from' => self::moment($weekFrom),
            'to' => self::moment($nowAt),
            'entries' => $submitted($weekFrom, $nowAt),
            'entriesBefore' => $submitted($prevFrom, $weekFrom),
            'missed' => $missed($weekFrom, $nowAt),
            'missedBefore' => $missed($prevFrom, $weekFrom),
            // Open tasks due by the end of today, overdue ones included.
            'tasksDue' => Tasks::visible($member)->whereIn('status', Task::OPEN)->where('due_at', '<=', $nowAt->setTimezone($tz)->endOfDay()->utc())
                ->when(! $member->can(Tasks::MANAGE), fn ($q) => $q->where('assignee_id', $member->id))->count(),
            'byLogbook' => $byLogbook->map(fn ($n, $id) => ['id' => $id, 'name' => $logbookNames[$id] ?? 'Logbook', 'count' => (int) $n])->values(),
        ];

        return Inertia::render('home', [
            'week' => $week,
            'charts' => self::charts($member, $readable, $nowAt, $tz),
            'operations' => $operations,
            'figures' => [
                'logbooks' => Access::logbooks()->where('status', 'active')->count(),
                'sheets' => Access::sheets()->where('status', 'active')->count(),
                'entries' => $readable()->count(),
                'today' => $readable()->where('submitted_at', '>=', $today)->count(),
                'pending' => $readable()->where('status', 'submitted')->count(),
                'reviewed' => $readable()->where('status', 'reviewed')->count(),
                'returned' => $readable()->where('status', 'returned')->count(),
                // Entries of the last 7 days with a reading outside its normal range.
                'outOfRange' => $readable()->where('occurred_at', '>=', now()->subDays(7))
                    ->with('version:id,fields')->get(['id', 'log_sheet_version_id', 'values'])
                    ->filter(fn (Entry $e) => Fields::deviations($e->version->fields, $e->values ?? []) !== [])->count(),
            ],
            'myDrafts' => Entry::query()->where('created_by', $me)->whereIn('status', ['draft', 'returned'])->count(),
            'awaitingMyReview' => Gate::allows('logbook.entries.review') ? Access::reviewable()->count() : null,
            'frequentSheets' => $frequent->keys()->filter(fn ($id) => $writable->has($id))->map(function ($id) use ($writable, $frequent) {
                /** @var LogSheet $sheet */
                $sheet = $writable[$id];

                return ['id' => $sheet->id, 'name' => $sheet->name, 'code' => $sheet->code, 'logbook' => $sheet->logbook->name, 'count' => (int) $frequent[$id]];
            })->values(),
            'writableSheets' => self::writableSheets(),
            // Recent activity: audit events in reachable logbooks (everything for auditors), never details of values.
            'activity' => AuditEvent::query()
                ->when(! $seesAudit, fn ($q) => $q->whereIn('action', ['entry.submitted', 'entry.resubmitted', 'entry.reviewed', 'entry.returned', 'template.published', 'logbook.created'])
                    ->where(fn ($w) => $w
                        ->where(fn ($e) => $e->where('subject_type', 'entry')->whereIn('subject_id', Access::entries()->where('status', '!=', 'draft')->select('id')))
                        ->orWhere(fn ($o) => $o->where('subject_type', '!=', 'entry')->whereIn('logbook_id', $recentLogbookIds))))
                ->latest('created_at')->limit(12)->get()
                ->map(fn (AuditEvent $e) => [
                    'id' => $e->id, 'action' => $e->action, 'subject' => $e->subject_label, 'subjectType' => $e->subject_type,
                    'subjectId' => $e->subject_id, 'user' => $e->user_name, 'at' => self::time($e->created_at),
                ]),
        ]);
    }

    /**
     * The dashboard's charts, over what the member reaches: entries per day
     * for two weeks, how the week's expected entries went, tasks by status
     * and open exceptions by severity.
     *
     * @param  callable(): Builder<Entry>  $readable
     * @return array<string, mixed>
     */
    private static function charts(Member $member, callable $readable, CarbonImmutable $nowAt, string $tz): array
    {
        // Entries per local day, the last 14 days (today included), grouped here so any database works.
        $firstDay = $nowAt->setTimezone($tz)->startOfDay()->subDays(13);
        $perDay = $readable()->where('submitted_at', '>=', $firstDay->utc())->pluck('submitted_at')
            ->countBy(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz)->toDateString());
        $days = [];
        for ($d = $firstDay; $d->lte($nowAt->setTimezone($tz)); $d = $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'entries' => (int) ($perDay[$d->toDateString()] ?? 0)];
        }

        // The last 7 days of expected entries: on time, late, missed, still open, excused.
        $occurrences = Schedules::visible($member)->where('due_at', '>=', $nowAt->subDays(7))->where('due_at', '<=', $nowAt)
            ->get(['status', 'timeliness']);
        $compliance = [
            'onTime' => $occurrences->filter(fn ($o) => $o->status === 'completed' && $o->timeliness !== 'late')->count(),
            'late' => $occurrences->filter(fn ($o) => $o->status === 'late' || ($o->status === 'completed' && $o->timeliness === 'late'))->count(),
            'missed' => $occurrences->where('status', 'missed')->count(),
            'open' => $occurrences->where('status', 'open')->count(),
            'excused' => $occurrences->where('status', 'excused')->count(),
        ];

        $tasks = Tasks::visible($member)->whereIn('status', [...Task::OPEN, 'awaiting_verification'])
            ->when(! $member->can(Tasks::MANAGE), fn ($q) => $q->where('assignee_id', $member->id))
            ->get(['status', 'due_at']);
        $taskStatus = [
            'overdue' => $tasks->filter(fn ($t) => $t->status !== 'awaiting_verification' && $t->due_at !== null && $t->due_at->lt($nowAt))->count(),
        ];
        foreach (['pending', 'in_progress', 'returned', 'awaiting_verification'] as $status) {
            $taskStatus[$status] = $tasks->where('status', $status)->count();
        }

        $severity = Exceptions::visible($member)->whereIn('status', LogException::OPEN)->toBase()
            ->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity');

        return [
            'perDay' => $days,
            'compliance' => $compliance,
            'tasks' => $taskStatus,
            'severity' => collect(['critical', 'high', 'medium', 'low'])->mapWithKeys(fn ($s) => [$s => (int) ($severity[$s] ?? 0)]),
        ];
    }
}
