<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\Approval;
use App\Procurement\Models\ApprovalRule;
use App\Procurement\Models\Award;
use App\Procurement\Models\Plan;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The approval engine shared by plans, requisitions, sourcing events,
 * awards and purchase orders (ADR-0023 §2.6).
 *
 * On submission the most specific active rule for the document type is
 * chosen (department, then method, then the highest amount band, in the
 * workspace currency), and its steps are laid out in order. A step names
 * an approve permission (anyone holding it) or one member (who must also
 * hold the type's approve permission). Without a rule, one step with the
 * type's approve permission applies; sourcing needs no approval unless a
 * rule asks for it.
 *
 * Separation of duties: whoever submitted never decides (unless settings
 * allow it), and nobody decides two steps of one submission.
 */
final class Approvals
{
    public const TYPES = ['plan', 'requisition', 'sourcing', 'award', 'order'];

    /** What a step without a rule (and a member step) requires. */
    public const PERMISSION = [
        'plan' => 'procurement.plans.approve',
        'requisition' => 'procurement.requisitions.approve',
        'sourcing' => 'procurement.sourcing.manage',
        'award' => 'procurement.awards.approve',
        'order' => 'procurement.orders.approve',
    ];

    /** Permissions a rule's step may name. */
    public const STEP_PERMISSIONS = [
        'procurement.requisitions.approve' => 'Requisition approvers',
        'procurement.plans.approve' => 'Plan approvers',
        'procurement.awards.approve' => 'Award approvers',
        'procurement.orders.approve' => 'Order approvers',
    ];

    public const LABELS = [
        'plan' => 'Procurement plan',
        'requisition' => 'Requisition',
        'sourcing' => 'Sourcing event',
        'award' => 'Supplier selection',
        'order' => 'Purchase order',
    ];

    /**
     * Lays out the approval steps for a submission. False when nothing needs
     * approving (the caller moves the document on at once).
     */
    public static function start(string $type, Model $document, int $baseMinor, ?string $departmentId, ?string $method, User $submitter): bool
    {
        $rule = self::ruleFor($type, $baseMinor, $departmentId, $method);
        // Without a rule: one step by the document's approvers; sourcing needs none.
        $permission = self::PERMISSION[$type];
        $default = array_key_exists($permission, self::STEP_PERMISSIONS)
            ? [['type' => 'permission', 'value' => $permission, 'label' => self::STEP_PERMISSIONS[$permission]]]
            : [];
        /** @var list<array{type: string, value: string, label?: string}> $steps */
        $steps = $rule !== null ? $rule->steps : $default;

        if ($steps === []) {
            return false;
        }

        $round = self::lastRound($type, $document) + 1;
        foreach ($steps as $i => $step) {
            Approval::query()->create([
                'document_type' => $type,
                'document_id' => $document->getKey(),
                'round' => $round,
                'step' => $i + 1,
                'rule_id' => $rule?->id,
                'approver_type' => $step['type'],
                'approver_value' => $step['value'],
                'approver_label' => $step['label'] ?? $step['value'],
                'submitted_by' => $submitter->id,
                'status' => 'pending',
            ]);
        }

        return true;
    }

    public static function ruleFor(string $type, int $baseMinor, ?string $departmentId, ?string $method): ?ApprovalRule
    {
        return ApprovalRule::query()
            ->where('document_type', $type)
            ->where('active', true)
            ->where('min_base_minor', '<=', $baseMinor)
            ->where(fn ($q) => $q->whereNull('max_base_minor')->orWhere('max_base_minor', '>=', $baseMinor))
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $departmentId))
            ->where(fn ($q) => $q->whereNull('method')->orWhere('method', $method))
            ->get()
            ->sortBy([
                fn (ApprovalRule $a, ApprovalRule $b) => ($b->department_id !== null) <=> ($a->department_id !== null),
                fn (ApprovalRule $a, ApprovalRule $b) => ($b->method !== null) <=> ($a->method !== null),
                fn (ApprovalRule $a, ApprovalRule $b) => $b->min_base_minor <=> $a->min_base_minor,
            ])
            ->first();
    }

    /** The step now waiting, if any. */
    public static function current(string $type, Model $document): ?Approval
    {
        return Approval::query()
            ->where('document_type', $type)
            ->where('document_id', $document->getKey())
            ->where('round', self::lastRound($type, $document))
            ->where('status', 'pending')
            ->orderBy('step')
            ->first();
    }

    /**
     * Why this person may not decide the step; null when they may.
     */
    public static function refusal(Approval $step, User $user): ?string
    {
        $permission = self::PERMISSION[$step->getAttribute('document_type')];

        if ($step->getAttribute('approver_type') === 'member') {
            if ($step->getAttribute('approver_value') !== $user->id) {
                return 'This step waits for '.$step->getAttribute('approver_label').'.';
            }
            if (! Gate::allows($permission)) {
                return 'You no longer hold the permission this step needs.';
            }
        } elseif (! Gate::allows((string) $step->getAttribute('approver_value'))) {
            return 'This step waits for '.$step->getAttribute('approver_label').'.';
        }

        if ($step->getAttribute('submitted_by') === $user->id && ! Workspace::settings()->allow_self_approval) {
            return 'You submitted this, so someone else must decide it.';
        }

        $already = Approval::query()
            ->where('document_type', $step->getAttribute('document_type'))
            ->where('document_id', $step->getAttribute('document_id'))
            ->where('round', $step->getAttribute('round'))
            ->where('decided_by', $user->id)
            ->exists();
        if ($already) {
            return 'You decided an earlier step of this submission; the next step needs someone else.';
        }

        return null;
    }

    public static function canDecide(string $type, Model $document, User $user): bool
    {
        $step = self::current($type, $document);

        return $step !== null && self::refusal($step, $user) === null;
    }

    /**
     * Records a decision on the waiting step. Returns "complete" when the
     * last step was approved, "pending" when more steps remain, or
     * "rejected" / "returned". The caller holds the document's row lock.
     *
     * @throws ValidationException
     */
    public static function decide(string $type, Model $document, User $user, string $decision, ?string $note): string
    {
        $step = self::current($type, $document);
        if ($step === null) {
            throw ValidationException::withMessages(['decision' => 'Nothing here is waiting for approval.']);
        }
        if (($reason = self::refusal($step, $user)) !== null) {
            throw ValidationException::withMessages(['decision' => $reason]);
        }
        if (! in_array($decision, ['approve', 'reject', 'return'], true)) {
            throw ValidationException::withMessages(['decision' => 'Approve, reject or return.']);
        }
        if ($decision !== 'approve' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why, so the submitter knows what to change.']);
        }

        $step->forceFill([
            'status' => match ($decision) {
                'approve' => 'approved',
                'reject' => 'rejected',
                'return' => 'returned',
            },
            'decided_by' => $user->id,
            'decided_by_name' => $user->name,
            'decided_at' => now(),
            'note' => $note,
        ])->save();

        if ($decision !== 'approve') {
            self::closeRound($type, $document, 'skipped');

            return $decision === 'reject' ? 'rejected' : 'returned';
        }

        return self::current($type, $document) === null ? 'complete' : 'pending';
    }

    /** Steps still waiting are no longer needed (withdrawn, cancelled). */
    public static function withdraw(string $type, Model $document): void
    {
        self::closeRound($type, $document, 'withdrawn');
    }

    /**
     * Every decision on a document, for its history.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(string $type, Model $document): array
    {
        return array_values(Approval::query()
            ->where('document_type', $type)
            ->where('document_id', $document->getKey())
            ->orderBy('round')->orderBy('step')
            ->get()
            ->map(fn (Approval $a) => [
                'id' => $a->id,
                'round' => $a->round,
                'step' => $a->step,
                'approver' => $a->getAttribute('approver_label'),
                'status' => $a->getAttribute('status'),
                'decidedBy' => $a->getAttribute('decided_by_name'),
                'decidedAt' => $a->decided_at?->toIso8601String(),
                'note' => $a->getAttribute('note'),
            ])->all());
    }

    /**
     * What waits for this person's decision, for the approvals inbox.
     *
     * @return list<array{type: string, typeLabel: string, id: string, number: string, title: string, amount: string|null, submittedAt: string|null, step: string}>
     */
    public static function awaiting(User $user): array
    {
        $pending = Approval::query()->where('status', 'pending')->orderBy('step')->get()
            ->groupBy(fn (Approval $a) => $a->getAttribute('document_type').'|'.$a->getAttribute('document_id'));

        $rows = [];
        foreach ($pending as $steps) {
            $round = $steps->max('round');
            /** @var Approval $step */
            $step = $steps->where('round', $round)->sortBy('step')->first();
            if (self::refusal($step, $user) !== null) {
                continue;
            }
            $document = self::find($step->getAttribute('document_type'), $step->getAttribute('document_id'));
            if ($document === null) {
                continue;
            }
            $rows[] = [
                'type' => $step->getAttribute('document_type'),
                'typeLabel' => self::LABELS[$step->getAttribute('document_type')],
                ...self::summary($document),
                'submittedAt' => $step->created_at?->toIso8601String(),
                'step' => 'Step '.$step->step.': '.$step->getAttribute('approver_label'),
            ];
        }

        return $rows;
    }

    public static function find(string $type, string $id): ?Model
    {
        return match ($type) {
            'plan' => Plan::query()->find($id),
            'requisition' => Requisition::query()->find($id),
            'sourcing' => SourcingEvent::query()->find($id),
            'award' => Award::query()->with(['event', 'supplier', 'quotation'])->find($id),
            'order' => PurchaseOrder::query()->with('supplier')->find($id),
            default => null,
        };
    }

    /**
     * @return array{id: string, number: string, title: string, amount: string|null}
     */
    private static function summary(Model $document): array
    {
        return match (true) {
            $document instanceof Requisition => ['id' => $document->id, 'number' => $document->number, 'title' => $document->title, 'amount' => Money::format($document->total_minor, $document->currency)],
            $document instanceof Plan => ['id' => $document->id, 'number' => $document->number, 'title' => $document->title, 'amount' => null],
            $document instanceof SourcingEvent => ['id' => $document->id, 'number' => $document->number, 'title' => $document->title, 'amount' => null],
            $document instanceof Award => ['id' => $document->sourcing_event_id, 'number' => $document->event->number, 'title' => $document->supplier->name, 'amount' => Money::format($document->quotation->total_minor, $document->quotation->currency)],
            $document instanceof PurchaseOrder => ['id' => $document->id, 'number' => $document->number, 'title' => $document->supplier->name, 'amount' => Money::format($document->total_minor, $document->currency)],
            default => ['id' => (string) $document->getKey(), 'number' => '', 'title' => '', 'amount' => null],
        };
    }

    private static function lastRound(string $type, Model $document): int
    {
        return (int) Approval::query()->where('document_type', $type)->where('document_id', $document->getKey())->max('round');
    }

    private static function closeRound(string $type, Model $document, string $status): void
    {
        Approval::query()
            ->where('document_type', $type)
            ->where('document_id', $document->getKey())
            ->where('status', 'pending')
            ->update(['status' => $status, 'updated_at' => now()]);
    }
}
