<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\ExpenseStatus;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantAmendment;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The money rules of grants, in one place:
 * - Before award, a grant's amount is a request and can change freely;
 *   once awarded it changes only by a numbered amendment.
 * - Allocations to projects and scheduled disbursements never add up to
 *   more than the grant. Checks run in a transaction on the locked grant.
 * - A grant closes only when no report is still owed.
 * Every change is audited.
 */
final class Grants
{
    /**
     * @param  array<string, mixed>  $attributes  validated: funder_id, reference, title, description, currency, amount_minor, restricted, start_on, end_on
     */
    public static function create(array $attributes, GrantStatus $status, User $by): Grant
    {
        abort_unless($status->isPipeline(), 422, 'A grant starts as an opportunity or an application.');

        return DB::transaction(function () use ($attributes, $status, $by) {
            $grant = Grant::query()->create([
                ...$attributes,
                'status' => $status,
                'applied_on' => $status === GrantStatus::Applied ? today() : null,
                'created_by' => $by->id,
            ]);
            Audit::record('grant.created', $grant, ['reference' => $grant->reference, 'status' => $status->value], actor: $by);

            return $grant;
        });
    }

    /**
     * Names, dates and terms. The amount, currency and end date are only
     * edited before award (afterwards: amend()).
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public static function update(Grant $grant, array $details, User $by): void
    {
        if (! $grant->status->isPipeline()) {
            foreach (['amount_minor', 'currency', 'end_on'] as $field) {
                $now = $grant->getAttribute($field);
                $now = $now instanceof CarbonImmutable ? $now->toDateString() : $now;
                if (array_key_exists($field, $details) && (string) $details[$field] !== (string) $now) {
                    throw ValidationException::withMessages([self::fieldName($field) => 'An awarded grant changes amount or end date by an amendment.']);
                }
                unset($details[$field]);
            }
        }

        $before = $grant->only(array_keys($details));
        $grant->fill($details)->save();
        Audit::record('grant.updated', $grant, ['before' => self::plain($before), 'after' => self::plain($grant->only(array_keys($details)))], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function apply(Grant $grant, CarbonImmutable $on, User $by): void
    {
        self::requireStatus($grant, [GrantStatus::Opportunity], 'Only an opportunity can be applied for.');
        $grant->forceFill(['status' => GrantStatus::Applied, 'applied_on' => $on])->save();
        Audit::record('grant.applied', $grant, ['on' => $on->toDateString()], actor: $by);
    }

    /**
     * The funder said yes: the amount awarded and the agreement's dates.
     *
     * @throws ValidationException
     */
    public static function award(Grant $grant, int $amountMinor, CarbonImmutable $on, ?CarbonImmutable $startOn, ?CarbonImmutable $endOn, User $by): void
    {
        self::requireStatus($grant, [GrantStatus::Opportunity, GrantStatus::Applied], 'This grant is already decided.');
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount awarded must be more than zero.']);
        }
        if ($startOn !== null && $endOn !== null && $endOn->lt($startOn)) {
            throw ValidationException::withMessages(['end_on' => 'The agreement cannot end before it starts.']);
        }

        $requested = $grant->amount_minor;
        $grant->forceFill([
            'status' => GrantStatus::Active,
            'amount_minor' => $amountMinor,
            'awarded_on' => $on,
            'start_on' => $startOn ?? $grant->start_on,
            'end_on' => $endOn ?? $grant->end_on,
        ])->save();
        Audit::record('grant.awarded', $grant, ['requested_minor' => $requested, 'awarded_minor' => $amountMinor, 'currency' => $grant->currency, 'on' => $on->toDateString()], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function decline(Grant $grant, CarbonImmutable $on, User $by): void
    {
        self::requireStatus($grant, [GrantStatus::Opportunity, GrantStatus::Applied], 'Only a grant not yet awarded can be declined.');
        $grant->forceFill(['status' => GrantStatus::Declined, 'declined_on' => $on])->save();
        Audit::record('grant.declined', $grant, ['on' => $on->toDateString()], actor: $by);
    }

    /**
     * Closeout: refused while a report is still owed to the funder.
     *
     * @throws ValidationException
     */
    public static function close(Grant $grant, CarbonImmutable $on, User $by): void
    {
        self::requireStatus($grant, [GrantStatus::Active], 'Only an active grant can be closed.');
        $owed = $grant->reports()->get()->filter(fn ($report) => $report->status->isOutstanding());
        if ($owed->isNotEmpty()) {
            throw ValidationException::withMessages(['grant' => 'Reports still owed to the funder: '.$owed->pluck('title')->implode(', ').'. Submit them before closing.']);
        }
        if (Expense::query()->where('grant_id', $grant->id)->whereIn('status', [ExpenseStatus::Requested, ExpenseStatus::Approved])->exists()) {
            throw ValidationException::withMessages(['grant' => 'Spending charged to this grant is still awaiting approval or payment.']);
        }

        $grant->forceFill(['status' => GrantStatus::Closed, 'closed_on' => $on])->save();
        Audit::record('grant.closed', $grant, [
            'on' => $on->toDateString(),
            'amount_minor' => $grant->amount_minor,
            'allocated_minor' => $grant->allocatedMinor(),
            'received_minor' => $grant->receivedMinor(),
        ], actor: $by);
    }

    /**
     * A change to an awarded grant's amount or end date, recorded as the
     * next numbered amendment. The amount cannot fall below what is already
     * allocated or scheduled.
     *
     * @throws ValidationException
     */
    public static function amend(Grant $grant, int $amountMinor, ?CarbonImmutable $endOn, CarbonImmutable $effectiveOn, string $reason, User $by): GrantAmendment
    {
        return DB::transaction(function () use ($grant, $amountMinor, $endOn, $effectiveOn, $reason, $by) {
            $grant = self::locked($grant);
            self::requireStatus($grant, [GrantStatus::Active], 'Only an active grant can be amended.');

            if ($amountMinor === $grant->amount_minor && $endOn?->toDateString() === $grant->end_on?->toDateString()) {
                throw ValidationException::withMessages(['amount' => 'Change the amount or the end date.']);
            }
            $floor = max($grant->allocatedMinor(), $grant->scheduledMinor());
            if ($amountMinor < $floor) {
                throw ValidationException::withMessages(['amount' => 'The grant cannot be less than what is already allocated or scheduled ('.Money::format($floor, $grant->currency).').']);
            }
            if ($endOn !== null && $grant->start_on !== null && $endOn->lt($grant->start_on)) {
                throw ValidationException::withMessages(['end_on' => 'The agreement cannot end before it starts.']);
            }

            $amendment = $grant->amendments()->create([
                'number' => (int) $grant->amendments()->max('number') + 1,
                'effective_on' => $effectiveOn,
                'reason' => $reason,
                'amount_before_minor' => $grant->amount_minor,
                'amount_after_minor' => $amountMinor,
                'end_before' => $grant->end_on,
                'end_after' => $endOn,
                'recorded_by' => $by->id,
            ]);
            $grant->forceFill(['amount_minor' => $amountMinor, 'end_on' => $endOn])->save();

            Audit::record('grant.amended', $grant, [
                'number' => $amendment->number,
                'amount_before_minor' => $amendment->amount_before_minor,
                'amount_after_minor' => $amountMinor,
                'end_before' => $amendment->end_before?->toDateString(),
                'end_after' => $endOn?->toDateString(),
            ], actor: $by);

            return $amendment;
        });
    }

    /**
     * Gives part of an active grant to a project, or changes how much.
     * The rate is required when the currencies differ (project currency per
     * unit of grant currency); the project's value is computed from it and
     * kept with it.
     *
     * @throws ValidationException
     */
    public static function allocate(Grant $grant, Project $project, int $amountMinor, ?string $rate, ?string $note, User $by): GrantAllocation
    {
        return DB::transaction(function () use ($grant, $project, $amountMinor, $rate, $note, $by) {
            $grant = self::locked($grant);
            self::requireStatus($grant, [GrantStatus::Active], 'Only an awarded, open grant can be allocated.');
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Allocate more than zero.']);
            }

            $existing = $grant->allocations()->where('project_id', $project->id)->first();
            $elsewhere = $grant->allocatedMinor() - ($existing->amount_minor ?? 0);
            $available = $grant->amount_minor - $elsewhere;
            if ($amountMinor > $available) {
                throw ValidationException::withMessages(['amount' => 'Only '.Money::format($available, $grant->currency).' of this grant is left to allocate.']);
            }

            if ($grant->currency === $project->currency) {
                $rate = '1';
            } elseif (! Money::isRate($rate)) {
                throw ValidationException::withMessages(['exchange_rate' => "The project is in {$project->currency}: give the rate ({$project->currency} per 1 {$grant->currency})."]);
            }

            $projectAmount = Money::convert($amountMinor, $grant->currency, $project->currency, $rate);
            $charged = Spending::chargedToGrant($project->id, $grant->id);
            if ($projectAmount < $charged) {
                throw ValidationException::withMessages(['amount' => Money::format($charged, $project->currency).' is already committed or spent on this grant here.']);
            }

            $allocation = $existing ?? new GrantAllocation(['grant_id' => $grant->id, 'project_id' => $project->id, 'created_by' => $by->id]);
            $before = $existing?->only(['amount_minor', 'exchange_rate', 'project_amount_minor']);
            $allocation->fill([
                'amount_minor' => $amountMinor,
                'exchange_rate' => $rate,
                'project_amount_minor' => $projectAmount,
                'note' => $note,
            ])->save();

            Audit::record($existing === null ? 'grant.allocated' : 'grant.allocation_changed', $grant, [
                'project' => $project->code,
                'before' => $before,
                'amount_minor' => $amountMinor,
                'exchange_rate' => $rate,
                'project_amount_minor' => $allocation->project_amount_minor,
            ], actor: $by);
            ActivityLog::record($project, $by, "Funding from {$grant->reference}: ".Money::format($allocation->project_amount_minor, $project->currency));

            return $allocation;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function removeAllocation(GrantAllocation $allocation, User $by): void
    {
        $grant = $allocation->grant;
        self::requireStatus($grant, [GrantStatus::Active], 'A closed grant keeps its allocations.');
        if (Spending::chargedToGrant($allocation->project_id, $grant->id) > 0) {
            throw ValidationException::withMessages(['allocation' => 'Spending is charged to this grant here: the allocation stays.']);
        }

        $allocation->delete();
        Audit::record('grant.allocation_removed', $grant, ['project' => $allocation->project->code, 'amount_minor' => $allocation->amount_minor], actor: $by);
        ActivityLog::record($allocation->project, $by, "Funding from {$grant->reference} withdrawn");
    }

    /**
     * A payment the funder is due to make. Scheduled payments never add up
     * to more than the grant.
     *
     * @throws ValidationException
     */
    public static function schedule(Grant $grant, CarbonImmutable $dueOn, int $amountMinor, ?string $note, User $by): GrantDisbursement
    {
        return DB::transaction(function () use ($grant, $dueOn, $amountMinor, $note, $by) {
            $grant = self::locked($grant);
            self::requireStatus($grant, [GrantStatus::Active], 'Payments are scheduled on an awarded, open grant.');
            $left = $grant->amount_minor - $grant->scheduledMinor();
            if ($amountMinor <= 0 || $amountMinor > $left) {
                throw ValidationException::withMessages(['amount' => 'Schedule between more than zero and '.Money::format($left, $grant->currency).' (what is not yet scheduled).']);
            }

            $disbursement = $grant->disbursements()->create(['due_on' => $dueOn, 'amount_minor' => $amountMinor, 'note' => $note]);
            Audit::record('grant.disbursement_scheduled', $grant, ['due_on' => $dueOn->toDateString(), 'amount_minor' => $amountMinor], actor: $by);

            return $disbursement;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function receive(GrantDisbursement $disbursement, CarbonImmutable $on, User $by): void
    {
        if ($disbursement->received_on !== null) {
            throw ValidationException::withMessages(['disbursement' => 'This payment is already recorded as received.']);
        }
        if ($on->gt(today())) {
            throw ValidationException::withMessages(['received_on' => 'A payment cannot be received in the future.']);
        }

        DB::transaction(function () use ($disbursement, $on, $by) {
            $disbursement->forceFill(['received_on' => $on])->save();
            $grant = Grant::query()->findOrFail($disbursement->grant_id);
            app(ProjectBooks::class)->grantReceived($disbursement, $grant);
            Audit::record('grant.disbursement_received', $disbursement, ['amount_minor' => $disbursement->amount_minor, 'on' => $on->toDateString()], actor: $by);
        });
    }

    /**
     * Only a payment not yet received can be taken off the schedule.
     *
     * @throws ValidationException
     */
    public static function unschedule(GrantDisbursement $disbursement, User $by): void
    {
        if ($disbursement->received_on !== null) {
            throw ValidationException::withMessages(['disbursement' => 'A received payment stays on record.']);
        }

        $disbursement->delete();
        Audit::record('grant.disbursement_removed', $disbursement, ['due_on' => $disbursement->due_on->toDateString(), 'amount_minor' => $disbursement->amount_minor], actor: $by);
    }

    /**
     * Moves a funder report along (due → submitted → accepted, or back to
     * owed when the funder asks for a revision).
     *
     * @throws ValidationException
     */
    public static function moveReport(GrantReport $report, GrantReportStatus $to, CarbonImmutable $on, User $by): void
    {
        if (! in_array($to, $report->status->next(), true)) {
            throw ValidationException::withMessages(['status' => "A report that is {$report->status->label()} cannot become {$to->label()}."]);
        }

        $from = $report->status;
        $report->forceFill([
            'status' => $to,
            'submitted_on' => $to === GrantReportStatus::Submitted ? $on : $report->submitted_on,
            'accepted_on' => $to === GrantReportStatus::Accepted ? $on : null,
        ])->save();
        if ($to === GrantReportStatus::Submitted) {
            DonorPortal::reportSubmitted($report, $by);
        }
        Audit::record('grant.report_'.$to->value, $report, ['title' => $report->title, 'from' => $from->value, 'on' => $on->toDateString()], actor: $by);
    }

    /**
     * The grant row, locked until the transaction ends, so two people
     * allocating at once cannot both spend the same balance.
     */
    private static function locked(Grant $grant): Grant
    {
        return Grant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<GrantStatus>  $allowed
     *
     * @throws ValidationException
     */
    private static function requireStatus(Grant $grant, array $allowed, string $message): void
    {
        if (! in_array($grant->status, $allowed, true)) {
            throw ValidationException::withMessages(['grant' => $message]);
        }
    }

    private static function fieldName(string $attribute): string
    {
        return $attribute === 'amount_minor' ? 'amount' : $attribute;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof CarbonImmutable => $value->toDateString(),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        }, $values);
    }
}
