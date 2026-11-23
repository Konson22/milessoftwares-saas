<?php

namespace App\Procurement;

use App\Models\User;
use App\Procurement\Models\Plan;
use App\Procurement\Models\PlanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Procurement plans (ADR-0023 §3): drafted with items, submitted for
 * approval, revised back to draft with a new revision, closed at the end of
 * the year. Requisitions may (or, by setting, must) name an item of an
 * approved plan.
 */
final class Plans
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Plan $plan, array $data, User $by): Plan
    {
        if ($plan !== null && $plan->status !== 'draft') {
            throw ValidationException::withMessages(['plan' => 'Only a draft plan can be changed. Revise it first.']);
        }

        return DB::transaction(function () use ($plan, $data, $by) {
            $model = $plan ?? new Plan(['number' => Numbers::next('plan'), 'status' => 'draft', 'created_by' => $by->id]);
            $model->fill([
                'title' => $data['title'],
                'fiscal_year' => $data['fiscal_year'],
                'department_id' => $data['department_id'] ?? null,
                'department_name' => Departments::name($data['department_id'] ?? null),
                'notes' => $data['notes'] ?? null,
            ])->save();
            AuditTrail::record($plan === null ? 'plan.created' : 'plan.updated', $model, $by);

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveItem(Plan $plan, ?PlanItem $item, array $data, User $by): PlanItem
    {
        if ($plan->status !== 'draft') {
            throw ValidationException::withMessages(['plan' => 'Only a draft plan can be changed. Revise it first.']);
        }

        return DB::transaction(function () use ($plan, $item, $data, $by) {
            $base = Money::base();
            $model = $item ?? new PlanItem(['plan_id' => $plan->id]);
            $model->fill([
                'description' => $data['description'],
                'category' => $data['category'] ?? null,
                'method' => $data['method'],
                'quantity' => Money::quantity($data['quantity']),
                'unit' => $data['unit'] ?? null,
                'estimated_base_minor' => Money::parse($data['estimated_amount'], $base, 'estimated_amount'),
                'expected_on' => $data['expected_on'] ?? null,
                'funding_reference' => $data['funding_reference'] ?? null,
                'project_reference' => $data['project_reference'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
            ])->save();
            AuditTrail::record($item === null ? 'plan.item_added' : 'plan.item_updated', $plan, $by, ['item' => $model->description]);

            return $model;
        });
    }

    public static function removeItem(Plan $plan, PlanItem $item, User $by): void
    {
        if ($plan->status !== 'draft') {
            throw ValidationException::withMessages(['plan' => 'Only a draft plan can be changed. Revise it first.']);
        }
        if ($item->requisitions()->exists()) {
            throw ValidationException::withMessages(['item' => 'Requisitions name this item; it stays.']);
        }

        DB::transaction(function () use ($plan, $item, $by) {
            AuditTrail::record('plan.item_removed', $plan, $by, ['item' => $item->description]);
            $item->delete();
        });
    }

    public static function submit(Plan $plan, User $by): void
    {
        DB::transaction(function () use ($plan, $by) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'draft') {
                throw ValidationException::withMessages(['plan' => 'Only a draft plan can be submitted.']);
            }
            if (! $plan->items()->exists()) {
                throw ValidationException::withMessages(['plan' => 'Add the planned purchases first.']);
            }

            $total = (int) $plan->items()->sum('estimated_base_minor');
            $needsApproval = Approvals::start('plan', $plan, $total, $plan->department_id, null, $by);
            $plan->forceFill(['status' => $needsApproval ? 'pending_approval' : 'approved', 'submitted_at' => now(), 'approved_at' => $needsApproval ? null : now()])->save();
            AuditTrail::record('plan.submitted', $plan, $by, ['revision' => $plan->revision]);
        });
    }

    public static function decide(Plan $plan, string $decision, ?string $note, User $by): void
    {
        DB::transaction(function () use ($plan, $decision, $note, $by) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'pending_approval') {
                throw ValidationException::withMessages(['decision' => 'This plan is not waiting for approval.']);
            }

            $outcome = Approvals::decide('plan', $plan, $by, $decision, $note);
            match ($outcome) {
                'complete' => $plan->forceFill(['status' => 'approved', 'approved_at' => now()])->save(),
                'rejected', 'returned' => $plan->forceFill(['status' => 'draft'])->save(),
                default => null,
            };
            AuditTrail::record('plan.'.($outcome === 'complete' ? 'approved' : ($outcome === 'pending' ? 'step_approved' : $outcome)), $plan, $by, ['note' => $note]);
        });
    }

    public static function revise(Plan $plan, string $reason, User $by): void
    {
        DB::transaction(function () use ($plan, $reason, $by) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'approved') {
                throw ValidationException::withMessages(['plan' => 'Only an approved plan is revised.']);
            }
            $plan->forceFill(['status' => 'draft', 'revision' => $plan->revision + 1, 'approved_at' => null])->save();
            AuditTrail::record('plan.revised', $plan, $by, ['reason' => $reason, 'revision' => $plan->revision]);
        });
    }

    public static function close(Plan $plan, User $by): void
    {
        DB::transaction(function () use ($plan, $by) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'approved') {
                throw ValidationException::withMessages(['plan' => 'Only an approved plan is closed.']);
            }
            $plan->forceFill(['status' => 'closed'])->save();
            AuditTrail::record('plan.closed', $plan, $by);
        });
    }
}
