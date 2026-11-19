<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Fields;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overview: figures over what the member reaches, what waits on them,
 * and the sheets they write on most.
 */
class HomeController extends Controller
{
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

        return Inertia::render('home', [
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
}
