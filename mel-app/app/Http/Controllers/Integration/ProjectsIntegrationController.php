<?php

namespace App\Http\Controllers\Integration;

use App\Mel\Models\EvidenceFile;
use App\Mel\Models\Indicator;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\VerifiedResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * What Projects may read from M&E (ADR-0019 §20): the published results of
 * the reporting units linked to one project, for a partner report.
 *
 *   GET projects/{project}/verified-results?from=Y-m-d&to=Y-m-d
 *
 * The policy, decided here and nowhere else (ADR-0019 §20.4):
 *   - Only units an M&E manager has marked to report to their linked
 *     project (shares_with_project_reports). Linking alone shares nothing.
 *   - Verified and published results only. Publishing is M&E's release of a
 *     verified result beyond the M&E team; results that are entered, under
 *     review, or verified but not yet published stay in M&E.
 *   - Indicators that are active or retired, never drafts.
 *   - A report period is required: no open-ended reads.
 *   - Standing results only: a corrected result's earlier value is not sent.
 *     One standing result per indicator, unit and period, so nothing is
 *     counted twice; nothing is added up here.
 *   - Only units linked to this project, in the organization the platform
 *     signed for. Periods overlapping [from, to] when given.
 *   - Each result says who M&E releases it to in the project's reports
 *     (audience: owner, partner, donors; and the partner organization),
 *     as set per unit by an M&E manager. Projects shows it accordingly
 *     (ADR-0019 §24); the default is the project owner only.
 *   - Evidence: name, type and size of files marked for sharing with donors;
 *     never the file, its path or anything from Data Collection, and no
 *     narrative, disaggregation cells or names of people.
 *
 * "linked" tells an unlinked project (false) from a linked one; "shared"
 * (units marked to report) tells a linked project nobody chose to report to
 * (0) from one with nothing published for the period (data empty).
 */
final class ProjectsIntegrationController
{
    private const LIMIT = 200;

    public function verifiedResults(Request $request, string $project): JsonResponse
    {
        if (! Str::isUuid($project)) {
            return response()->json(['error' => 'invalid', 'detail' => 'The project id is not a uuid.'], 422);
        }
        $validator = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid', 'detail' => $validator->errors()->first()], 422);
        }
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');

        $linked = ProgrammeUnit::query()->where('linked_project_id', strtolower($project))->get(['id', 'shares_with_project_reports', 'project_report_audience', 'project_report_partner_organization_id']);
        $audiences = $linked->keyBy('id');
        $units = $linked->where('shares_with_project_reports', true)->pluck('id');
        if ($linked->isEmpty() || $units->isEmpty()) {
            return response()->json(['linked' => $linked->isNotEmpty(), 'units' => $linked->count(), 'shared' => 0, 'truncated' => false, 'data' => []]);
        }

        $results = VerifiedResult::query()
            ->with(['indicator:id,code', 'version:id,name,measure,unit_label', 'period:id,label,starts_on,ends_on', 'unit:id,code,name'])
            ->whereIn('unit_id', $units)
            ->standing()
            ->whereNotNull('verified_at')
            ->whereNotNull('published_at')
            ->whereHas('indicator', fn ($indicator) => $indicator->whereIn('status', [Indicator::ACTIVE, Indicator::RETIRED]))
            ->whereHas('period', fn ($period) => $period->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from))
            ->orderBy('indicator_id')->orderBy('reporting_period_id')->orderBy('unit_id')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $results->count() > self::LIMIT;
        $results = $results->take(self::LIMIT);

        $evidence = EvidenceFile::query()
            ->whereIn('submission_id', $results->pluck('submission_id'))
            ->where('share_with_donors', true)
            ->orderBy('original_name')
            ->get(['submission_id', 'original_name', 'mime_type', 'size'])
            ->groupBy('submission_id');

        return response()->json([
            'linked' => true,
            'units' => $linked->count(),
            'shared' => $units->count(),
            'truncated' => $truncated,
            'data' => $results->map(fn (VerifiedResult $result) => [
                'id' => $result->id,
                'indicatorId' => $result->indicator_id,
                'indicatorVersionId' => $result->indicator_version_id,
                'indicatorCode' => $result->indicator->code,
                'indicator' => $result->version->name,
                'measure' => $result->version->measure,
                'unitLabel' => $result->version->unit_label,
                'unitId' => $result->unit_id,
                'unit' => $result->unit->name,
                'periodId' => $result->reporting_period_id,
                'period' => $result->period->label,
                'periodStartsOn' => $result->period->starts_on->toDateString(),
                'periodEndsOn' => $result->period->ends_on->toDateString(),
                'value' => $result->value,
                'numerator' => $result->numerator,
                'denominator' => $result->denominator,
                'verifiedAt' => $result->verified_at->toIso8601String(),
                'publishedAt' => $result->published_at?->toIso8601String(),
                // Who M&E releases it to in the project's reports (ADR-0019 §24).
                'audience' => $audiences[$result->unit_id]->project_report_audience ?? 'owner',
                'partnerOrganizationId' => $audiences[$result->unit_id]->project_report_partner_organization_id ?? null,
                'evidence' => ($evidence[$result->submission_id] ?? collect())->map(fn (EvidenceFile $file) => [
                    'name' => $file->original_name,
                    'mimeType' => $file->mime_type,
                    'size' => (int) $file->size,
                ])->values()->all(),
            ])->values(),
        ]);
    }
}
