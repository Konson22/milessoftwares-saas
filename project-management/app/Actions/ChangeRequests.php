<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of change requests:
 * - What is asked never changes once raised: it is decided as written, or
 *   withdrawn. Nothing is deleted.
 * - Whoever raised a request does not decide it.
 * - Approving moves the project's due date when that is asked.
 * - Approving never changes a budget. A budget change starts a draft version
 *   (App\Actions\Budgets), which is written, submitted and approved like any
 *   other. If a version is awaiting approval the request waits too.
 * Every step runs in a transaction on the locked project and is audited.
 */
final class ChangeRequests
{
    /**
     * @param  array{title: string, description: string, proposed_due_on: string|null, budget_change_minor: int|null}  $details
     *
     * @throws ValidationException
     */
    public static function raise(Project $project, array $details, User $by): ChangeRequest
    {
        return DB::transaction(function () use ($project, $details, $by) {
            $locked = self::lock($project);
            self::ensureOpen($locked);

            /** @var ChangeRequest $change */
            $change = $locked->changeRequests()->create([
                ...$details,
                'number' => ((int) $locked->changeRequests()->max('number')) + 1,
                'status' => ChangeRequest::SUBMITTED,
                'raised_by' => $by->id,
            ]);
            $reference = $change->reference($locked->code);
            Audit::record('change_request.raised', $change, ['reference' => $reference, 'title' => $change->title, 'proposed_due_on' => $details['proposed_due_on'], 'budget_change_minor' => $details['budget_change_minor']], actor: $by);
            ActivityLog::record($locked, $by, "Raised the change request {$reference}: {$change->title}");
            Alerts::changeRaised($locked, $change, $by);

            return $change;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function approve(ChangeRequest $change, ?string $note, User $by): void
    {
        DB::transaction(function () use ($change, $note, $by) {
            $project = self::lock($change->project);
            self::ensureOpen($project);
            self::ensureDecidable($change, $by);

            $version = $change->budget_change_minor === null ? null : Budgets::revise($project, $by);

            $previous = $project->due_on;
            if ($change->proposed_due_on !== null) {
                $project->forceFill(['due_on' => $change->proposed_due_on->toDateString()])->save();
            }

            $change->forceFill([
                'status' => ChangeRequest::APPROVED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'previous_due_on' => $change->proposed_due_on === null ? null : $previous?->toDateString(),
                'budget_version_id' => $version?->id,
            ])->save();

            $reference = $change->reference($project->code);
            Audit::record('change_request.approved', $change, [
                'reference' => $reference,
                'due_on_from' => $previous?->toDateString(),
                'due_on_to' => $change->proposed_due_on?->toDateString(),
                'budget_version' => $version?->number,
            ], actor: $by);
            ActivityLog::record($project, $by, "Approved the change request {$reference}");
            Alerts::changeDecided($project, $change, "{$by->name} approved {$reference}", $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function reject(ChangeRequest $change, string $note, User $by): void
    {
        DB::transaction(function () use ($change, $note, $by) {
            $project = self::lock($change->project);
            self::ensureDecidable($change, $by);

            $change->forceFill([
                'status' => ChangeRequest::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $reference = $change->reference($project->code);
            Audit::record('change_request.rejected', $change, ['reference' => $reference, 'reason' => $note], actor: $by);
            ActivityLog::record($project, $by, "Rejected the change request {$reference}");
            Alerts::changeDecided($project, $change, "{$by->name} rejected {$reference}", $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function withdraw(ChangeRequest $change, User $by): void
    {
        DB::transaction(function () use ($change, $by) {
            $project = self::lock($change->project);
            $change->refresh();
            if (! $change->isSubmitted()) {
                throw ValidationException::withMessages(['change' => 'This request has already been decided.']);
            }

            $change->forceFill(['status' => ChangeRequest::WITHDRAWN])->save();

            $reference = $change->reference($project->code);
            Audit::record('change_request.withdrawn', $change, ['reference' => $reference], actor: $by);
            ActivityLog::record($project, $by, "Withdrew the change request {$reference}");
        });
    }

    /**
     * Starts the budget draft again, when the one it started was discarded.
     *
     * @throws ValidationException
     */
    public static function restartBudget(ChangeRequest $change, User $by): void
    {
        DB::transaction(function () use ($change, $by) {
            $project = self::lock($change->project);
            self::ensureOpen($project);
            $change->refresh();
            if ($change->status !== ChangeRequest::APPROVED || $change->budget_change_minor === null || $change->budget_version_id !== null) {
                throw ValidationException::withMessages(['change' => 'This request has no budget draft to start.']);
            }

            $version = Budgets::revise($project, $by);
            $change->forceFill(['budget_version_id' => $version->id])->save();
            Audit::record('change_request.budget_restarted', $change, ['reference' => $change->reference($project->code), 'budget_version' => $version->number], actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    private static function ensureDecidable(ChangeRequest $change, User $by): void
    {
        $change->refresh();
        if (! $change->isSubmitted()) {
            throw ValidationException::withMessages(['change' => 'This request has already been decided.']);
        }
        if ($change->raised_by === $by->id) {
            throw ValidationException::withMessages(['change' => 'Someone other than whoever raised the request decides it.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function ensureOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['change' => "{$project->code} is closed."]);
        }
    }

    private static function lock(Project $project): Project
    {
        return Project::query()->lockForUpdate()->whereKey($project->id)->firstOrFail();
    }
}
