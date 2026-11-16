<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Access;
use App\Mel\Calculator;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Settings;
use App\Mel\Models\Submission;
use App\Mel\Models\VerifiedResult;
use App\Mel\Performance;
use App\Mel\Reporting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The M&E overview: programmes, what is due and missing, what waits for
 * review, how indicators stand against their targets (per indicator, as
 * counts) and what was published lately.
 */
class HomeController extends Controller
{
    public function __construct(private readonly Reporting $reporting) {}

    public function __invoke(Request $request): Response
    {
        $access = $this->access();
        $sees = $access->sees();
        $today = CarbonImmutable::today();
        $programmes = $access->programmes()->whereNull('archived_at')->orderBy('code')->get();
        $selected = $programmes->firstWhere('id', $request->string('programme')->toString());
        $scope = $selected === null ? $programmes : collect([$selected]);
        $settings = Settings::settings();

        $attention = [];
        $statusCounts = array_fill_keys([Performance::ON_TRACK, Performance::ATTENTION, Performance::OFF_TRACK, Performance::NO_TARGET, Performance::NO_DATA], 0);
        $trend = null;

        foreach ($scope as $programme) {
            $rows = $this->reporting->performance($programme);
            $offTrack = 0;
            foreach ($rows as $row) {
                // Each indicator's latest period that has started.
                $latest = collect($row['periods'])->filter(fn (array $cell) => $cell['endsOn'] <= $today->toDateString() || $cell['actual'] !== null)->last();
                if ($latest !== null) {
                    $statusCounts[$latest['assessment']['status']]++;
                    $offTrack += $latest['assessment']['status'] === Performance::OFF_TRACK ? 1 : 0;
                }
            }

            $missing = $sees === Access::SEE_ALL ? $this->overdue($programme, $today) : 0;
            if ($offTrack > 0 || $missing > 0) {
                $attention[] = ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name, 'offTrack' => $offTrack, 'overdue' => $missing];
            }

            if ($selected !== null) {
                $trend = collect($rows)->filter(fn (array $row) => collect($row['periods'])->contains(fn (array $cell) => $cell['actual'] !== null))->take(6)->values()->all();
            }
        }

        return Inertia::render('mel/home', [
            'provisioned' => true,
            'programmes' => $programmes->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()]),
            'selected' => $selected?->id,
            'stats' => [
                'activeProgrammes' => $scope->where('status', Programme::ACTIVE)->count(),
                'indicators' => Indicator::query()->whereIn('programme_id', $scope->pluck('id'))->where('status', Indicator::ACTIVE)->count(),
                'awaitingReview' => $sees === Access::SEE_ALL ? Submission::query()->whereIn('programme_id', $scope->pluck('id'))->whereIn('status', [Submission::SUBMITTED, Submission::UNDER_REVIEW])->count() : null,
                'awaitingPublication' => $sees === Access::SEE_ALL ? Submission::query()->whereIn('programme_id', $scope->pluck('id'))->where('status', Submission::VERIFIED)->count() : null,
                'sentBack' => $sees === Access::SEE_ALL ? Submission::query()->whereIn('programme_id', $scope->pluck('id'))->where('status', Submission::REJECTED)->count() : null,
                'overdue' => $sees === Access::SEE_ALL ? $scope->sum(fn (Programme $programme) => $this->overdue($programme, $today)) : null,
            ],
            'statusCounts' => $statusCounts,
            'thresholds' => ['onTrack' => $settings->on_track_percent, 'attention' => $settings->attention_percent],
            'deadlines' => $sees === Access::SEE_ALL ? ReportingPeriod::query()
                ->whereIn('programme_id', $scope->pluck('id'))
                ->where('status', ReportingPeriod::OPEN)
                ->whereBetween('due_on', [$today->subDays(30)->toDateString(), $today->addDays(45)->toDateString()])
                ->with('programme')->orderBy('due_on')->limit(8)->get()
                ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($period->programme, $period->starts_on, $period->ends_on))
                ->map(fn (ReportingPeriod $period) => [
                    'programmeId' => $period->programme_id,
                    'programme' => $period->programme->code,
                    'periodId' => $period->id,
                    'period' => $period->label,
                    'dueOn' => $period->due_on->toDateString(),
                    'overdue' => $period->due_on->lt($today),
                ])->values() : [],
            'attention' => $attention,
            'trend' => $trend,
            'published' => VerifiedResult::query()
                ->whereIn('programme_id', $scope->pluck('id'))
                ->whereNotNull('published_at')->whereNull('superseded_at')
                ->with(['indicator', 'version', 'period', 'unit', 'submission.programme'])
                ->orderByDesc('published_at')->limit(30)->get()
                ->filter(fn (VerifiedResult $result) => $access->reachesUnit($result->submission->programme, $result->unit_id)
                    && $access->reachesPeriod($result->submission->programme, $result->period->starts_on, $result->period->ends_on))
                ->take(8)
                ->map(fn (VerifiedResult $result) => [
                    'submissionId' => $result->submission_id,
                    'indicator' => $result->indicator->code,
                    'name' => $result->version->name,
                    'period' => $result->period->label,
                    'unit' => $result->unit->code,
                    'value' => Calculator::valueOf($result->version, $result->only(['value', 'numerator', 'denominator'])),
                    'measure' => $result->version->measure,
                    'decimals' => $result->version->decimals,
                    'unitLabel' => $result->version->unit_label,
                    'publishedAt' => $result->published_at?->toIso8601String(),
                ])->values(),
            'can' => [
                'createProgramme' => $access->can('mel.programmes.manage'),
                'enter' => $access->can('mel.data.enter'),
                'review' => $access->can('mel.data.review'),
            ],
        ]);
    }

    /**
     * Results past their due date with nothing entered, in the units the
     * member reaches.
     */
    private function overdue(Programme $programme, CarbonImmutable $today): int
    {
        $past = $this->reporting->periods($programme)->filter(fn (ReportingPeriod $period) => $period->due_on->lt($today) && $period->isOpen());
        if ($past->isEmpty()) {
            return 0;
        }

        return $this->reporting->completeness($programme, $past->values())['totals']['overdue'];
    }
}
