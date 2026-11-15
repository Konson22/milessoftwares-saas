<?php

namespace App\Collaboration;

use App\Actions\Budgets;
use App\Collaboration\Models\Report;
use App\Enums\CostCategory;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\VerifiedResult;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectFinance;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A report version's structured content (ADR-0015 §4): what the submitting
 * party writes, validated per section, and, on submission, what the system
 * records alongside it. Figures carry their source; nothing written by a
 * party is treated as accounting data.
 */
final class ReportContent
{
    public const IMPLEMENTATION = [
        'activities_planned' => 'Activities planned',
        'activities_completed' => 'Activities completed',
        'milestones' => 'Milestones',
        'deliverables' => 'Deliverables',
        'delays' => 'Delays',
        'challenges' => 'Challenges',
        'risks' => 'Risks',
        'corrective_actions' => 'Corrective actions',
    ];

    public const NARRATIVE = [
        'achievements' => 'Achievements',
        'challenges' => 'Challenges',
        'lessons' => 'Lessons learned',
        'next_period' => 'Plans for the next period',
    ];

    public const REPORTED = 'reported';

    public const RECORDED = 'recorded';

    public const SOURCES = [
        self::REPORTED => 'Reported by the organization, not verified',
        self::RECORDED => 'Recorded in Tatook Projects, not verified by Finance',
    ];

    private const MAX_TEXT = 10000;

    private const MAX_LINES = 50;

    /**
     * @return array{implementation: array<string, string|null>, financial: array{lines: list<array{label: string, amountMinor: int|null, source: string}>, note: string|null}, narrative: array<string, string|null>}
     */
    public static function empty(): array
    {
        return [
            'implementation' => array_fill_keys(array_keys(self::IMPLEMENTATION), null),
            'financial' => ['lines' => [], 'note' => null],
            'narrative' => array_fill_keys(array_keys(self::NARRATIVE), null),
        ];
    }

    /**
     * What the party may write; anything else is dropped. The system section
     * is never taken from input.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{implementation: array<string, string|null>, financial: array{lines: list<array{label: string, amountMinor: int|null, source: string}>, note: string|null}, narrative: array<string, string|null>}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $rules = ['financial.lines' => ['array', 'max:'.self::MAX_LINES], 'financial.lines.*.label' => ['required', 'string', 'max:200'], 'financial.lines.*.amountMinor' => ['nullable', 'integer', 'between:-999999999999999,999999999999999'], 'financial.note' => ['nullable', 'string', 'max:'.self::MAX_TEXT]];
        foreach (array_keys(self::IMPLEMENTATION) as $key) {
            $rules["implementation.{$key}"] = ['nullable', 'string', 'max:'.self::MAX_TEXT];
        }
        foreach (array_keys(self::NARRATIVE) as $key) {
            $rules["narrative.{$key}"] = ['nullable', 'string', 'max:'.self::MAX_TEXT];
        }
        $valid = Validator::make($input, $rules, [], ['financial.lines.*.label' => 'line description'])->validate();

        $content = self::empty();
        foreach (array_keys(self::IMPLEMENTATION) as $key) {
            $content['implementation'][$key] = self::text($valid['implementation'][$key] ?? null);
        }
        foreach (array_keys(self::NARRATIVE) as $key) {
            $content['narrative'][$key] = self::text($valid['narrative'][$key] ?? null);
        }
        foreach ($valid['financial']['lines'] ?? [] as $line) {
            $content['financial']['lines'][] = [
                'label' => trim((string) $line['label']),
                'amountMinor' => isset($line['amountMinor']) ? (int) $line['amountMinor'] : null,
                'source' => self::REPORTED,
            ];
        }
        $content['financial']['note'] = self::text($valid['financial']['note'] ?? null);

        return $content;
    }

    /**
     * Whether anything has been written.
     *
     * @param  array<string, mixed>  $content
     */
    public static function isBlank(array $content): bool
    {
        foreach (['implementation', 'narrative'] as $section) {
            foreach ((array) ($content[$section] ?? []) as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return false;
                }
            }
        }

        return ($content['financial']['lines'] ?? []) === [] && ($content['financial']['note'] ?? null) === null;
    }

    /**
     * What the system records with a submission, read in the owner's
     * workspace: the project's recorded budget figures, only when the
     * submitting party may see them, and the verified M&E results of units
     * linked to the project for periods overlapping the report's.
     *
     * @return array{capturedAt: string, financial: array{source: string, currency: string, approvedMinor: int, spentMinor: int, committedMinor: int, varianceMinor: int, basis: string, provenance: array<string, mixed>, categories: list<array{category: string, amountMinor: int}>}|null, mel: list<array<string, mixed>>}
     */
    public static function snapshot(Report $report, bool $withBudget): array
    {
        $owner = Organization::query()->findOrFail($report->organization_id);

        return app(CurrentOrganization::class)->run($owner, function () use ($report, $withBudget) {
            $project = Project::query()->findOrFail($report->project_id);

            return [
                'capturedAt' => now()->toIso8601String(),
                'financial' => $withBudget ? self::financial($project) : null,
                'mel' => self::mel($project, $report),
            ];
        });
    }

    /**
     * @return array{source: string, currency: string, approvedMinor: int, spentMinor: int, committedMinor: int, varianceMinor: int, basis: string, provenance: array<string, mixed>, categories: list<array{category: string, amountMinor: int}>}|null
     */
    private static function financial(Project $project): ?array
    {
        // ProjectFinance::shareable: never the estimated staff cost.
        $figures = ProjectFinance::shareable(ProjectFinance::for($project));
        if ($figures['approvedBudgetMinor'] === null) {
            return null;
        }

        $categories = [];
        foreach (Budgets::approvedByCategory($project) as $category => $amount) {
            $categories[] = ['category' => CostCategory::from($category)->label(), 'amountMinor' => (int) $amount];
        }
        $approved = $figures['approvedBudgetMinor'];

        // spentMinor is the paid amount and varianceMinor approved − paid
        // (cash basis, unspent), the keys frozen snapshots already use; the
        // basis and provenance say so from now on.
        return [
            'source' => self::RECORDED,
            'currency' => $figures['currency'],
            'approvedMinor' => $approved,
            'spentMinor' => $figures['paidMinor'],
            'committedMinor' => $figures['committedMinor'],
            'varianceMinor' => $approved - $figures['paidMinor'],
            'basis' => 'paid',
            'provenance' => $figures['provenance'],
            'categories' => $categories,
        ];
    }

    /**
     * Verified results, as verified, each with its indicator and unit; never
     * added up here.
     *
     * @return list<array<string, mixed>>
     */
    private static function mel(Project $project, Report $report): array
    {
        $units = ProgrammeUnit::query()->where('linked_project_id', $project->id)->pluck('id');
        if ($units->isEmpty()) {
            return [];
        }

        $query = VerifiedResult::query()->with(['indicator', 'version', 'period', 'unit'])
            ->whereIn('unit_id', $units)->whereNull('superseded_at');
        if ($report->period_start !== null && $report->period_end !== null) {
            $start = $report->period_start->toDateString();
            $end = $report->period_end->toDateString();
            $query->whereHas('period', fn ($period) => $period->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start));
        }

        return array_values($query->limit(200)->get()->map(fn (VerifiedResult $result) => [
            'indicator' => $result->indicator->code.' · '.$result->version->name,
            'unit' => $result->unit->name,
            'period' => $result->period->label,
            'measure' => $result->version->measure,
            'unitLabel' => $result->version->unit_label,
            'value' => $result->value,
            'numerator' => $result->numerator,
            'denominator' => $result->denominator,
            'published' => $result->published_at !== null,
        ])->all());
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) (is_string($value) ? $value : ''));

        return $value === '' ? null : $value;
    }
}
