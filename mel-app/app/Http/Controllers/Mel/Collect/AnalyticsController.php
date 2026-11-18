<?php

namespace App\Http\Controllers\Mel\Collect;

use App\Collect\Models\Assignment;
use App\Collect\Models\Form;
use App\Collect\Models\Submission;
use App\Http\Controllers\Mel\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data collection at a glance: how much came in over a period, how its
 * review stands, by form and by collector, and each field assignment
 * against what was expected. Reach is App\Collect\Scope, the same as the
 * collected-data list; nothing here shows an answer.
 */
class AnalyticsController extends Controller
{
    /** The periods offered, in days, and how each is bucketed. */
    public const PERIODS = [30 => 'day', 90 => 'week', 365 => 'month'];

    /** Review outcomes, in the order the charts stack them. */
    public const OUTCOMES = ['awaiting', 'correction', 'rejected', 'accepted'];

    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', '30');
        $days = array_key_exists($days, self::PERIODS) ? $days : 30;
        $unit = self::PERIODS[$days];
        $formId = is_string($request->query('form')) ? $request->query('form') : null;
        $since = CarbonImmutable::now()->startOfDay()->subDays($days - 1);

        /** @return Builder<Submission> */
        $base = fn (): Builder => $this->scope()->submissions()
            ->where('status', '<>', Submission::RECEIVING)
            ->where('completed_at', '>=', $since)
            ->when($formId !== null, fn (Builder $q) => $q->where('form_id', $formId));

        $outcome = "CASE WHEN status IN ('submitted','under_review') THEN 'awaiting'
            WHEN status = 'correction_requested' THEN 'correction'
            WHEN status = 'rejected' THEN 'rejected' ELSE 'accepted' END";

        // Over time, by review outcome: counted per day, then per week or month.
        $day = self::dayExpression();
        $rows = $base()
            ->selectRaw("{$day} AS day, {$outcome} AS outcome, count(*) AS n")
            ->groupByRaw('1, 2')
            ->toBase()->get();
        $byBucket = [];
        foreach ($rows as $row) {
            $key = CarbonImmutable::parse((string) $row->day)->startOf($unit)->toDateString();
            $byBucket[$key][$row->outcome] = ($byBucket[$key][$row->outcome] ?? 0) + (int) $row->n;
        }
        $trend = [];
        for ($at = $since->startOf($unit); $at->lessThanOrEqualTo(CarbonImmutable::now()); $at = $at->add(1, $unit)) {
            $key = $at->toDateString();
            $trend[] = ['date' => $key] + array_combine(self::OUTCOMES, array_map(fn ($o) => $byBucket[$key][$o] ?? 0, self::OUTCOMES));
        }

        $totals = array_fill_keys(self::OUTCOMES, 0);
        foreach ($byBucket as $counts) {
            foreach ($counts as $o => $n) {
                $totals[$o] += $n;
            }
        }
        $received = array_sum($totals);
        $reviewed = $totals['accepted'] + $totals['rejected'] + $totals['correction'];

        // By form (top 8) and by collector (top 10).
        $byForm = $base()->selectRaw("form_id, {$outcome} AS outcome, count(*) AS n")->groupByRaw('1, 2')->toBase()->get();
        $forms = Form::query()->whereIn('id', $byForm->pluck('form_id')->unique())->pluck('name', 'id');
        $byAuthor = $base()->selectRaw('author_id, count(*) AS n')->groupBy('author_id')->orderByDesc('n')->limit(10)->toBase()->get();
        $people = User::query()->whereIn('id', $byAuthor->pluck('author_id'))->pluck('name', 'id');

        // Live field assignments with a target: sent against expected (all time, as on the assignments page).
        $assignments = Assignment::query()->live()
            ->whereIn('form_id', $this->scope()->forms()->select('id'))
            ->whereNotNull('expected_submissions')
            ->when($formId !== null, fn (Builder $q) => $q->where('form_id', $formId))
            ->with(['form:id,name', 'user:id,name'])
            ->get();
        $sent = $assignments->isEmpty() ? collect() : $this->scope()->submissions()
            ->whereIn('form_id', $assignments->pluck('form_id')->unique())
            ->whereIn('author_id', $assignments->pluck('user_id')->unique())
            ->whereIn('status', [Submission::SUBMITTED, Submission::UNDER_REVIEW, Submission::CORRECTION_REQUESTED, Submission::ACCEPTED])
            ->selectRaw('form_id, author_id, count(*) AS n')->groupBy('form_id', 'author_id')
            ->toBase()->get()->mapWithKeys(fn ($r) => ["{$r->form_id}|{$r->author_id}" => (int) $r->n]);

        return Inertia::render('mel/collect/analytics', [
            'filters' => ['days' => $days, 'form' => $formId],
            'unit' => $unit,
            'totals' => $totals + [
                'received' => $received,
                'collectors' => (int) $base()->distinct()->count('author_id'),
                'acceptanceRate' => $reviewed === 0 ? null : round($totals['accepted'] / $reviewed * 100),
            ],
            'trend' => $trend,
            'byForm' => $byForm->groupBy('form_id')
                ->map(fn ($group, $id) => ['name' => $forms[$id] ?? '—'] + array_combine(self::OUTCOMES, array_map(fn ($o) => (int) ($group->firstWhere('outcome', $o)->n ?? 0), self::OUTCOMES)))
                ->sortByDesc(fn ($row) => array_sum(array_intersect_key($row, array_flip(self::OUTCOMES))))
                ->take(8)->values(),
            'byCollector' => $byAuthor->map(fn ($r) => ['name' => $people[$r->author_id] ?? '—', 'count' => (int) $r->n])->values(),
            'targets' => $assignments->map(fn (Assignment $a) => [
                'id' => $a->id,
                'person' => $a->user->name,
                'form' => $a->form->name,
                'expected' => (int) $a->expected_submissions,
                'sent' => $sent["{$a->form_id}|{$a->user_id}"] ?? 0,
                'endsOn' => $a->ends_on?->toDateString(),
            ])->sortBy(fn ($row) => $row['sent'] / max(1, $row['expected']))->take(12)->values(),
            'forms' => $this->scope()->forms()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Form $form) => ['value' => $form->id, 'label' => $form->name])->values(),
        ]);
    }

    /** The local day of completed_at, on Postgres and SQLite (tests). */
    public static function dayExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "(completed_at AT TIME ZONE '".config('app.timezone')."')::date"
            : 'date(completed_at)';
    }
}
