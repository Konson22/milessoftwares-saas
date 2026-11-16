<?php

namespace App\Collaboration;

use App\Audit\Audit;
use App\Collaboration\Models\Obligation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Report;
use App\Collaboration\Models\ReportReview;
use App\Collaboration\Models\ReportVersion;
use App\Models\Project;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Partner reports (ADR-0015 §4): obligations and their periods, drafts and
 * frozen versions, review by the party they go to, publication as its own
 * step, and consolidation that lists its sources. Every action checks who
 * is acting and for which party, here.
 */
final class Reports
{
    /**
     * An obligation and its periods, each a report in draft. Set up by the
     * owner's reporting managers; the parties must be able to do their part.
     *
     * @param  array{title: string, category: string, frequency: string, starts_on: string, ends_on: string, due_days: int, responsible: string|null, reviewer: string|null, publish_after_approval?: bool}  $data
     *
     * @throws ValidationException
     */
    public static function createObligation(Acting $acting, array $data): Obligation
    {
        abort_unless($acting->isOwner(), 403);
        $acting->require('projects.reporting.manage');
        self::ensureOpen($acting->project);

        $responsible = self::party($acting->project, $data['responsible'], Capability::REPORTS_SUBMIT, 'responsible', 'submit reports');
        $reviewer = self::party($acting->project, $data['reviewer'], Capability::REPORTS_REVIEW, 'reviewer', 'review reports');
        if ($responsible !== null && $responsible->id === $reviewer?->id) {
            throw ValidationException::withMessages(['reviewer' => 'An organization cannot review its own reports.']);
        }
        if ($responsible !== null && $reviewer !== null && Routing::isUnder($reviewer, $responsible)) {
            throw ValidationException::withMessages(['reviewer' => 'Reports cannot go to an organization under the one reporting.']);
        }

        $periods = ReportPeriods::between($data['frequency'], CarbonImmutable::parse($data['starts_on']), CarbonImmutable::parse($data['ends_on']));

        return DB::transaction(function () use ($acting, $data, $responsible, $reviewer, $periods) {
            self::lockProject($acting->project);
            $obligation = new Obligation;
            $obligation->forceFill([
                'organization_id' => $acting->project->organization_id,
                'project_id' => $acting->project->id,
                'responsible_participant_id' => $responsible?->id,
                'reviewer_participant_id' => $reviewer?->id,
                'category' => $data['category'],
                'title' => $data['title'],
                'frequency' => $data['frequency'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'due_days' => $data['due_days'],
                'publish_after_approval' => (bool) ($data['publish_after_approval'] ?? false),
                'status' => Obligation::ACTIVE,
                'created_by' => $acting->user->id,
            ])->save();

            $number = self::lastNumber($acting->project);
            foreach ($periods as $period) {
                $exists = Report::query()->where('obligation_id', $obligation->id)->whereDate('period_start', $period['start']->toDateString())->exists();
                if ($exists) {
                    continue;
                }
                self::newReport($acting, [
                    'obligation_id' => $obligation->id,
                    'number' => ++$number,
                    'category' => $obligation->category,
                    'title' => count($periods) === 1 ? $obligation->title : "{$obligation->title}: {$period['start']->format('j M Y')} – {$period['end']->format('j M Y')}",
                    'period_start' => $period['start']->toDateString(),
                    'period_end' => $period['end']->toDateString(),
                    'due_on' => $period['end']->addDays($obligation->due_days)->toDateString(),
                    'submitter_participant_id' => $responsible?->id,
                    'reviewer_participant_id' => $reviewer?->id,
                ]);
            }

            self::audit($acting, 'report_obligation.created', $obligation, [
                'project' => $acting->project->code,
                'title' => $obligation->title,
                'frequency' => $obligation->frequency,
                'periods' => count($periods),
                'responsible' => Routing::partyName($acting->project, $responsible),
                'reviewer' => Routing::partyName($acting->project, $reviewer),
            ]);
            if ($responsible !== null) {
                Notify::party($acting->project, $responsible, 'projects.reports.prepare', 'reports', (string) Report::query()->where('obligation_id', $obligation->id)->orderBy('period_start')->value('id'), "{$acting->partyName()} asks for reports on {$acting->project->name}", "{$obligation->title}, ".count($periods).' period(s)', $acting->user);
            }

            return $obligation;
        });
    }

    /**
     * Stops an obligation; reports not yet submitted for periods that have
     * not started are withdrawn, the rest stay.
     */
    public static function endObligation(Obligation $obligation, Acting $acting): void
    {
        abort_unless($acting->isOwner() && $obligation->project_id === $acting->project->id, 404);
        $acting->require('projects.reporting.manage');
        if ($obligation->status !== Obligation::ACTIVE) {
            return;
        }

        DB::transaction(function () use ($obligation, $acting) {
            $obligation->forceFill(['status' => Obligation::ENDED])->save();
            // Every scheduled report never submitted is no longer expected:
            // cancelled, a final state (Report::isEditable). Reports already
            // submitted keep their history and status; withdrawn ones can no
            // longer be resubmitted, changes the reviewer asked for still can.
            $unsent = Report::query()->where('obligation_id', $obligation->id)->where('status', Report::DRAFT)
                ->whereNull('submitted_at')->get();
            foreach ($unsent as $report) {
                $report->forceFill(['status' => Report::CANCELLED])->save();
                self::step($report, $acting, 'cancelled', 'The obligation ended.');
            }
            self::audit($acting, 'report_obligation.ended', $obligation, ['project' => $acting->project->code, 'title' => $obligation->title, 'cancelled' => $unsent->count()]);
        });
    }

    /**
     * A report on its own, from the acting party, to the reviewer chosen or
     * the default one.
     *
     * @param  array{title: string, category: string, period_start: string|null, period_end: string|null, due_on: string|null, reviewer: string|null}  $data
     *
     * @throws ValidationException
     */
    public static function create(Acting $acting, array $data): Report
    {
        $acting->require('projects.reports.prepare', Capability::REPORTS_SUBMIT);
        self::ensureOpen($acting->project);
        $reviewer = Routing::reviewerFor($acting->project, $acting->participant, $data['reviewer'], Capability::REPORTS_REVIEW);

        return DB::transaction(function () use ($acting, $data, $reviewer) {
            self::lockProject($acting->project);
            $report = self::newReport($acting, [
                'obligation_id' => null,
                'number' => self::lastNumber($acting->project) + 1,
                'category' => $data['category'],
                'title' => $data['title'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'due_on' => $data['due_on'],
                'submitter_participant_id' => $acting->partyId(),
                'reviewer_participant_id' => $reviewer?->id,
            ]);
            self::audit($acting, 'partner_report.created', $report, ['project' => $acting->project->code, 'report' => $report->reference(), 'title' => $report->title, 'reviewer' => Routing::partyName($acting->project, $reviewer)]);

            return $report;
        });
    }

    /**
     * Saves what the submitting party wrote: on the open version, or a new
     * one when the last was submitted. Submitted versions stay as they were.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function saveDraft(Report $report, Acting $acting, array $input): ReportVersion
    {
        self::authorizeSubmitter($report, $acting);
        $content = ReportContent::validate($input);

        return DB::transaction(function () use ($report, $acting, $content) {
            $report = self::lock($report);
            self::ensureEditable($report);
            $version = $report->currentVersion;
            if ($version === null || $version->isFrozen()) {
                $version = new ReportVersion;
                $version->forceFill([
                    'report_id' => $report->id,
                    'number' => ((int) ReportVersion::query()->where('report_id', $report->id)->max('number')) + 1,
                    'content' => $content,
                    'created_by' => $acting->user->id,
                ])->save();
                $report->forceFill(['current_version_id' => $version->id])->save();
            } else {
                $version->forceFill(['content' => $content])->save();
            }

            return $version;
        });
    }

    /**
     * Sends the open version to the reviewer and freezes it, with what the
     * system records alongside. If the reviewer named earlier can no longer
     * review, it goes where the route leads now.
     *
     * @throws ValidationException
     */
    public static function submit(Report $report, Acting $acting): void
    {
        self::authorizeSubmitter($report, $acting);
        self::ensureOpen($acting->project);

        // Read from M&E before the transaction: a slow or absent M&E never
        // holds locks, and never stops the report going in (MelResults).
        $mel = app(MelResults::class)->forReport($report);

        DB::transaction(function () use ($report, $acting, $mel) {
            $report = self::lock($report);
            self::ensureEditable($report);
            $version = $report->currentVersion;
            if ($version === null || $version->isFrozen() || ReportContent::isBlank($version->content)) {
                throw ValidationException::withMessages(['report' => 'Write the report before submitting it.']);
            }

            $reviewerId = $report->reviewer_participant_id;
            $rerouted = false;
            if (! Routing::stillReviews($reviewerId, Capability::REPORTS_REVIEW)) {
                $reviewerId = Routing::defaultReviewer($acting->participant, Capability::REPORTS_REVIEW)?->id;
                $rerouted = true;
            }

            $content = $version->content;
            $content['system'] = ReportContent::snapshot($report, $acting->seesBudget(), $mel);
            $version->forceFill(['content' => $content, 'submitted_by' => $acting->user->id, 'submitted_at' => now()])->save();

            $again = $report->submitted_at !== null;
            $report->forceFill([
                'status' => Report::SUBMITTED,
                'reviewer_participant_id' => $reviewerId,
                'submitted_at' => now(),
                'decided_at' => null,
            ])->save();

            self::step($report, $acting, $again ? 'resubmitted' : 'submitted', null, $version);
            self::audit($acting, $again ? 'partner_report.resubmitted' : 'partner_report.submitted', $report, ['project' => $acting->project->code, 'report' => $report->reference(), 'version' => $version->number, 'rerouted' => $rerouted]);
            Notify::party($acting->project, $report->reviewer, 'projects.reports.review', 'reports', $report->id, "{$acting->partyName()} ".($again ? 'resubmitted' : 'submitted')." {$report->reference()}", $report->title, $acting->user, "report-submitted:{$report->id}:{$version->number}");
        });
    }

    /**
     * Takes a submitted report back before a decision.
     */
    public static function withdraw(Report $report, Acting $acting, ?string $comment): void
    {
        self::authorizeSubmitter($report, $acting);

        DB::transaction(function () use ($report, $acting, $comment) {
            $report = self::lock($report);
            if (! in_array($report->status, Report::IN_REVIEW, true)) {
                throw ValidationException::withMessages(['report' => 'Only a report waiting for review can be withdrawn.']);
            }
            $report->forceFill(['status' => Report::WITHDRAWN])->save();
            self::step($report, $acting, 'withdrawn', $comment, $report->currentVersion);
            self::audit($acting, 'partner_report.withdrawn', $report, ['project' => $acting->project->code, 'report' => $report->reference()]);
            Notify::party($acting->project, $report->reviewer, 'projects.reports.review', 'reports', $report->id, "{$acting->partyName()} withdrew {$report->reference()}", $report->title, $acting->user);
        });
    }

    /**
     * The reviewer's decisions, and comments from either party. Nobody
     * decides a version they submitted.
     *
     * @param  'start'|'approve'|'changes'|'reject'|'comment'  $action
     *
     * @throws ValidationException
     */
    public static function decide(Report $report, Acting $acting, string $action, ?string $comment): void
    {
        $comment = self::text($comment);
        if ($action === 'comment') {
            self::comment($report, $acting, $comment);

            return;
        }

        abort_unless(self::isReviewer($report, $acting), 403);
        $acting->require('projects.reports.review', Capability::REPORTS_REVIEW);
        if (in_array($action, ['changes', 'reject'], true) && $comment === null) {
            throw ValidationException::withMessages(['comment' => 'Say why.']);
        }

        DB::transaction(function () use ($report, $acting, $action, $comment) {
            $report = self::lock($report);
            $version = $report->currentVersion;
            if ($version?->submitted_by === $acting->user->id) {
                throw ValidationException::withMessages(['report' => 'You submitted this version; someone else must decide on it.']);
            }

            [$from, $to, $step] = match ($action) {
                'start' => [[Report::SUBMITTED], Report::UNDER_REVIEW, 'review_started'],
                'approve' => [Report::IN_REVIEW, Report::APPROVED, 'approved'],
                'changes' => [Report::IN_REVIEW, Report::CHANGES_REQUESTED, 'changes_requested'],
                'reject' => [Report::IN_REVIEW, Report::REJECTED, 'rejected'],
            };
            if (! in_array($report->status, $from, true)) {
                throw ValidationException::withMessages(['report' => 'This report is '.Report::STATUSES[$report->status].'.']);
            }

            $report->forceFill(['status' => $to, 'decided_at' => $action === 'start' ? $report->decided_at : now()])->save();
            self::step($report, $acting, $step, $comment, $version);
            self::audit($acting, "partner_report.{$step}", $report, ['project' => $acting->project->code, 'report' => $report->reference(), 'version' => $version?->number]);
            if ($action !== 'start') {
                $verb = ['approve' => 'approved', 'changes' => 'asked for changes to', 'reject' => 'rejected'][$action];
                Notify::party($acting->project, $report->submitter, 'projects.reports.prepare', 'reports', $report->id, "{$acting->partyName()} {$verb} {$report->reference()}", $comment ?? $report->title, $acting->user);
            }
        });
    }

    /**
     * Makes an approved report visible to the owner and to those allowed
     * to see published reports. A separate act from approving.
     *
     * @throws ValidationException
     */
    public static function publish(Report $report, Acting $acting): void
    {
        abort_unless(self::isReviewer($report, $acting) || ($acting->isOwner() && self::canSee($report, $acting)), 403);
        $acting->require('projects.reports.publish', Capability::REPORTS_PUBLISH);

        DB::transaction(function () use ($report, $acting) {
            $report = self::lock($report);
            if ($report->status !== Report::APPROVED) {
                throw ValidationException::withMessages(['report' => 'Only an approved report can be published.']);
            }
            $report->forceFill(['status' => Report::PUBLISHED, 'published_at' => now(), 'published_by' => $acting->user->id])->save();
            self::step($report, $acting, 'published', null, $report->currentVersion);
            self::audit($acting, 'partner_report.published', $report, ['project' => $acting->project->code, 'report' => $report->reference()]);
            Notify::party($acting->project, $report->submitter, 'projects.reports.prepare', 'reports', $report->id, "{$acting->partyName()} published {$report->reference()}", $report->title, $acting->user);
        });
    }

    /**
     * Adds an approved report this party received to one it is writing.
     * The source stays as it is; its figures are listed, not added up.
     *
     * @throws ValidationException
     */
    public static function addSource(Report $report, Report $source, Acting $acting): void
    {
        self::authorizeSubmitter($report, $acting);
        if ($source->project_id !== $report->project_id || $source->id === $report->id
            || ! $acting->is($source->reviewer_participant_id) || ! in_array($source->status, Report::ACCEPTED, true)) {
            throw ValidationException::withMessages(['source' => 'Choose an approved report sent to you on this project.']);
        }

        DB::transaction(function () use ($report, $source, $acting) {
            $report = self::lock($report);
            self::ensureEditable($report);
            $exists = DB::table('project_report_sources')->where('report_id', $report->id)->where('source_report_id', $source->id)->exists();
            if ($exists) {
                return;
            }
            DB::table('project_report_sources')->insert([
                'id' => (string) Str::uuid7(),
                'report_id' => $report->id,
                'source_report_id' => $source->id,
                'added_by' => $acting->user->id,
                'created_at' => now(),
            ]);
            self::audit($acting, 'partner_report.source_added', $report, ['report' => $report->reference(), 'source' => $source->reference()]);
        });
    }

    public static function removeSource(Report $report, Report $source, Acting $acting): void
    {
        self::authorizeSubmitter($report, $acting);
        self::ensureEditable($report);
        DB::table('project_report_sources')->where('report_id', $report->id)->where('source_report_id', $source->id)->delete();
        self::audit($acting, 'partner_report.source_removed', $report, ['report' => $report->reference(), 'source' => $source->reference()]);
    }

    /**
     * The approved reports this party received that it may build on.
     *
     * @return Collection<int, Report>
     */
    public static function sourceCandidates(Report $report, Acting $acting): Collection
    {
        return Report::query()->with('submitter.organization')->where('project_id', $report->project_id)->whereKeyNot($report->id)
            ->where(fn (Builder $query) => $acting->isOwner() ? $query->whereNull('reviewer_participant_id') : $query->where('reviewer_participant_id', $acting->partyId()))
            ->whereIn('status', Report::ACCEPTED)->orderByDesc('period_end')->limit(100)->get();
    }

    /**
     * Who sees a report: its submitter always; its reviewer once submitted;
     * once published, the owner and participants allowed to see published
     * reports.
     */
    public static function canSee(Report $report, Acting $acting): bool
    {
        if ($report->project_id !== $acting->project->id) {
            return false;
        }
        // On the owner's side only members who work on reports open the
        // owner's own unpublished ones; projects.view alone reads published.
        $works = ! $acting->isOwner() || self::worksOnReports($acting);
        if ($works && $acting->is($report->submitter_participant_id)) {
            return true;
        }
        if ($works && $acting->is($report->reviewer_participant_id) && $report->submitted_at !== null) {
            return true;
        }
        if ($report->status === Report::PUBLISHED) {
            return $acting->isOwner() || ParticipantAccess::allows($acting->participant, Capability::DONOR_REPORTS_VIEW);
        }

        return false;
    }

    /**
     * The reports a party may list (it opens only those canSee allows).
     *
     * The owner's members who work on reports list the owner's own reports,
     * everything published, and the reports due under the obligations the
     * owner set (status and due date only, to follow deadlines: a report
     * between two other parties is opened by the owner only once published).
     * A report another party sends to someone else outside any obligation
     * stays between them. Other members (projects.view only) list what is
     * published.
     *
     * @return Builder<Report>
     */
    public static function listed(Acting $acting): Builder
    {
        $query = Report::query()->where('project_id', $acting->project->id);
        if ($acting->isOwner()) {
            if (! self::worksOnReports($acting)) {
                return $query->where('status', Report::PUBLISHED);
            }

            return $query->where(fn (Builder $query) => $query
                ->whereNull('submitter_participant_id')
                ->orWhere(fn (Builder $query) => $query->whereNull('reviewer_participant_id')->whereNotNull('submitted_at'))
                ->orWhere('status', Report::PUBLISHED)
                ->orWhereNotNull('obligation_id'));
        }

        $id = $acting->partyId();
        $donorView = ParticipantAccess::allows($acting->participant, Capability::DONOR_REPORTS_VIEW);

        return $query->where(function (Builder $query) use ($id, $donorView) {
            $query->where('submitter_participant_id', $id)
                ->orWhere(fn (Builder $query) => $query->where('reviewer_participant_id', $id)->whereNotNull('submitted_at'));
            if ($donorView) {
                $query->orWhere('status', Report::PUBLISHED);
            }
        });
    }

    /**
     * An owner-side member who prepares, reviews or publishes reports, or
     * sets reporting obligations.
     */
    public static function worksOnReports(Acting $acting): bool
    {
        foreach (['projects.reports.prepare', 'projects.reports.review', 'projects.reports.publish', 'projects.reporting.manage'] as $permission) {
            if ($acting->access->has($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this member may read the financial section a party reported
     * (separate from seeing the report): the party that wrote it and the one
     * reviewing it, through members who prepare or review reports; anyone
     * else only with the budget permission (projects.finance.view on the
     * owner's side, budget.view on a participation).
     */
    public static function seesReportedFinance(Report $report, Acting $acting): bool
    {
        if ($acting->is($report->submitter_participant_id) && $acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT)) {
            return true;
        }
        if ($acting->is($report->reviewer_participant_id) && $acting->may('projects.reports.review', Capability::REPORTS_REVIEW)) {
            return true;
        }

        return $acting->seesBudget();
    }

    /**
     * The M&E results recorded with a report that this member may read
     * (ADR-0019 §24). Default deny, decided per result from what M&E
     * released with it and froze in the snapshot:
     *
     *   - The project's owner organization (projects.view): all of them,
     *     whose M&E data it is.
     *   - Audience "partner" or "donors": the partner organization M&E named
     *     for the result's unit, when it is the party that submitted this
     *     report (its own scope), and the party reviewing that report (the
     *     lead, within the reporting relationship), through members who
     *     prepare or review reports. A partner named for another unit, or a
     *     report between other parties, shows nothing.
     *   - Audience "donors": also donors (donor_reports.view) on the
     *     published report only.
     *   - Results recorded before M&E sent an audience: owner only.
     *
     * The audience is the one M&E gave when the version was submitted: a
     * later change in M&E applies to the next submission and never rewrites
     * a recorded one. Losing access to the report (a participation ended,
     * capabilities withdrawn) loses its results with it.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    public static function visibleMelResults(Report $report, Acting $acting, array $results): array
    {
        if ($acting->isOwner()) {
            return $acting->may('projects.view') ? $results : [];
        }

        $party = (self::isSubmitter($report, $acting) && $acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT))
            || (self::isReviewer($report, $acting) && $acting->may('projects.reports.review', Capability::REPORTS_REVIEW));
        $donor = $report->status === Report::PUBLISHED && $acting->participant !== null
            && ParticipantAccess::allows($acting->participant, Capability::DONOR_REPORTS_VIEW);
        if (! $party && ! $donor) {
            return [];
        }
        $submitterOrganization = $report->submitter_participant_id === null
            ? null
            : Participant::query()->whereKey($report->submitter_participant_id)->value('participant_organization_id');

        return array_values(array_filter($results, function (array $result) use ($party, $donor, $submitterOrganization): bool {
            $audience = $result['audience'] ?? 'owner';
            $partner = $result['partnerOrganizationId'] ?? null;
            if ($party && in_array($audience, ['partner', 'donors'], true) && is_string($partner) && $partner === $submitterOrganization) {
                return true;
            }

            return $donor && $audience === 'donors';
        }));
    }

    public static function isReviewer(Report $report, Acting $acting): bool
    {
        return $report->submitted_at !== null && $acting->is($report->reviewer_participant_id);
    }

    public static function isSubmitter(Report $report, Acting $acting): bool
    {
        return $acting->is($report->submitter_participant_id);
    }

    /**
     * @throws ValidationException
     */
    private static function comment(Report $report, Acting $acting, ?string $comment): void
    {
        abort_unless(self::isSubmitter($report, $acting) || self::isReviewer($report, $acting), 403);
        abort_unless($acting->may('projects.reports.prepare', Capability::REPORTS_SUBMIT) || $acting->may('projects.reports.review', Capability::REPORTS_REVIEW), 403);
        if ($comment === null) {
            throw ValidationException::withMessages(['comment' => 'Write a comment.']);
        }

        self::step($report, $acting, 'commented', $comment, $report->currentVersion);
        // The reviewer hears of it only once the report has reached it.
        if ($report->submitted_at === null) {
            return;
        }
        $fromSubmitter = self::isSubmitter($report, $acting);
        Notify::party($acting->project, $fromSubmitter ? $report->reviewer : $report->submitter, $fromSubmitter ? 'projects.reports.review' : 'projects.reports.prepare', 'reports', $report->id, "{$acting->user->name} commented on {$report->reference()}", $comment, $acting->user);
    }

    private static function authorizeSubmitter(Report $report, Acting $acting): void
    {
        abort_unless($report->project_id === $acting->project->id && self::isSubmitter($report, $acting), 403);
        $acting->require('projects.reports.prepare', Capability::REPORTS_SUBMIT);
    }

    /**
     * @throws ValidationException
     */
    private static function ensureEditable(Report $report): void
    {
        if (! $report->isEditable()) {
            throw ValidationException::withMessages(['report' => $report->obligationEnded() && in_array($report->status, Report::EDITABLE, true)
                ? 'The obligation this report was due under has ended: it can no longer be submitted.'
                : 'This report is '.Report::STATUSES[$report->status].' and cannot be changed.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function ensureOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => 'This project is closed.']);
        }
    }

    /**
     * A party for an obligation: '' or 'owner' is the owner; a participant
     * must be active and allowed.
     *
     * @throws ValidationException
     */
    private static function party(Project $project, ?string $id, string $capability, string $field, string $what): ?Participant
    {
        if ($id === null || $id === '' || $id === 'owner') {
            return null;
        }
        $participant = Participant::query()->where('project_id', $project->id)->where('status', Participant::ACTIVE)->find($id);
        if ($participant === null || ! ParticipantAccess::allows($participant, $capability)) {
            throw ValidationException::withMessages([$field => "Choose an organization on this project that may {$what}."]);
        }

        return $participant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function newReport(Acting $acting, array $attributes): Report
    {
        $report = new Report;
        $report->forceFill([
            ...$attributes,
            'organization_id' => $acting->project->organization_id,
            'project_id' => $acting->project->id,
            'status' => Report::DRAFT,
            'created_by' => $acting->user->id,
        ])->save();

        $version = new ReportVersion;
        $version->forceFill(['report_id' => $report->id, 'number' => 1, 'content' => ReportContent::empty(), 'created_by' => $acting->user->id])->save();
        $report->forceFill(['current_version_id' => $version->id])->save();

        return $report;
    }

    private static function step(Report $report, Acting $acting, string $action, ?string $comment, ?ReportVersion $version = null): void
    {
        $review = new ReportReview;
        $review->forceFill([
            'report_id' => $report->id,
            'version_id' => $version?->id,
            'action' => $action,
            'comment' => $comment,
            'user_id' => $acting->user->id,
            'acting_organization_id' => $acting->organizationId(),
            'created_at' => now(),
        ])->save();
    }

    private static function lock(Report $report): Report
    {
        return Report::query()->with(['currentVersion', 'submitter.organization', 'reviewer.organization'])->whereKey($report->id)->lockForUpdate()->firstOrFail();
    }

    private static function lockProject(Project $project): void
    {
        Project::acrossOrganizations()->whereKey($project->id)->lockForUpdate()->first();
    }

    private static function lastNumber(Project $project): int
    {
        return (int) Report::query()->where('project_id', $project->id)->max('number');
    }

    /**
     * Recorded for the owner and, when another organization acts, for it too.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function audit(Acting $acting, string $action, Model $subject, array $properties): void
    {
        $properties['acting_for'] = $acting->partyName();
        Audit::record($action, $subject, $properties, $acting->project->organization_id, $acting->user);
        if ($acting->organizationId() !== $acting->project->organization_id) {
            Audit::record($action, $subject, $properties, $acting->organizationId(), $acting->user);
        }
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 5000);
    }

    /**
     * For the daily run: reports this organization must write that are due
     * within a week or late, each told once per due date.
     */
    public static function remind(string $organizationId): int
    {
        $told = 0;
        $reports = Report::query()->with(['project', 'obligation', 'submitter.organization'])
            ->whereIn('status', Report::EDITABLE)->where('status', '!=', Report::WITHDRAWN)
            // Nothing is expected under an ended obligation.
            ->where(fn (Builder $query) => $query->whereNull('obligation_id')->orWhereHas('obligation', fn (Builder $query) => $query->where('status', Obligation::ACTIVE)))
            ->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(7)->toDateString())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('submitter_participant_id')->where('organization_id', $organizationId))
                ->orWhereHas('submitter', fn (Builder $query) => $query->where('participant_organization_id', $organizationId)->where('status', Participant::ACTIVE)))
            ->get();

        foreach ($reports as $report) {
            $project = Project::acrossOrganizations()->find($report->project_id);
            if ($project === null || ! $project->isOpen() || ($report->submitter !== null && ParticipantAccess::effective($report->submitter) === [])) {
                continue;
            }
            $due = $report->due_on?->toDateString();
            $overdue = $report->isOverdue();
            $told += Notify::party(
                $project,
                $report->submitter,
                'projects.reports.prepare',
                'reports',
                $report->id,
                $overdue ? "{$report->reference()} on {$project->name} is overdue" : "{$report->reference()} on {$project->name} is due on {$report->due_on?->format('j M Y')}",
                $report->title,
                null,
                ($overdue ? 'partner-report-overdue' : 'partner-report-due').":{$report->id}:{$due}",
                UserNotification::REMINDER,
            );
        }

        return $told;
    }
}
