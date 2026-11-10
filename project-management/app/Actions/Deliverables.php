<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of deliverables: planned, then delivered, then accepted or sent
 * back with a reason. Accepted and cancelled ones are final and nothing is
 * deleted. A closed project's stay as they are. Every step is audited.
 */
final class Deliverables
{
    /**
     * @param  array{name: string, description: string|null, milestone_id: string|null, owner_id: string|null, due_on: string|null}  $details
     *
     * @throws ValidationException
     */
    public static function add(Project $project, array $details, User $by): Deliverable
    {
        self::ensureOpen($project);

        /** @var Deliverable $deliverable */
        $deliverable = $project->deliverables()->create([...$details, 'status' => Deliverable::PLANNED, 'created_by' => $by->id]);
        Audit::record('deliverable.added', $deliverable, ['project' => $project->code, 'name' => $deliverable->name], actor: $by);
        ActivityLog::record($project, $by, "Added the deliverable {$deliverable->name}");

        return $deliverable;
    }

    /**
     * @param  array{name: string, description: string|null, milestone_id: string|null, owner_id: string|null, due_on: string|null}  $details
     *
     * @throws ValidationException
     */
    public static function update(Deliverable $deliverable, array $details, User $by): void
    {
        self::step($deliverable, [Deliverable::PLANNED, Deliverable::DELIVERED], function (Deliverable $locked) use ($details, $by) {
            $before = $locked->only(array_keys($details));
            $locked->fill($details)->save();
            Audit::change('deliverable.updated', $locked, $before, $locked->only(array_keys($details)), actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function deliver(Deliverable $deliverable, ?string $note, User $by): void
    {
        self::step($deliverable, [Deliverable::PLANNED], function (Deliverable $locked) use ($note, $by) {
            $locked->forceFill([
                'status' => Deliverable::DELIVERED,
                'delivery_note' => $note,
                'delivered_by' => $by->id,
                'delivered_at' => now(),
                'returned_reason' => null,
            ])->save();
            Audit::record('deliverable.delivered', $locked, ['name' => $locked->name], actor: $by);
            ActivityLog::record($locked->project, $by, "Delivered {$locked->name}");
        });
    }

    /**
     * @throws ValidationException
     */
    public static function accept(Deliverable $deliverable, User $by): void
    {
        self::step($deliverable, [Deliverable::DELIVERED], function (Deliverable $locked) use ($by) {
            $locked->forceFill(['status' => Deliverable::ACCEPTED, 'accepted_by' => $by->id, 'accepted_at' => now()])->save();
            Audit::record('deliverable.accepted', $locked, ['name' => $locked->name, 'delivered_by' => $locked->delivered_by], actor: $by);
            ActivityLog::record($locked->project, $by, "Accepted {$locked->name}");
        });
    }

    /**
     * @throws ValidationException
     */
    public static function sendBack(Deliverable $deliverable, string $reason, User $by): void
    {
        self::step($deliverable, [Deliverable::DELIVERED], function (Deliverable $locked) use ($reason, $by) {
            $locked->forceFill([
                'status' => Deliverable::PLANNED,
                'returned_reason' => $reason,
                'delivered_by' => null,
                'delivered_at' => null,
                'delivery_note' => null,
            ])->save();
            Audit::record('deliverable.sent_back', $locked, ['name' => $locked->name, 'reason' => $reason], actor: $by);
            ActivityLog::record($locked->project, $by, "Sent {$locked->name} back: {$reason}");
        });
    }

    /**
     * @throws ValidationException
     */
    public static function cancel(Deliverable $deliverable, User $by): void
    {
        self::step($deliverable, [Deliverable::PLANNED, Deliverable::DELIVERED], function (Deliverable $locked) use ($by) {
            $locked->forceFill(['status' => Deliverable::CANCELLED])->save();
            Audit::record('deliverable.cancelled', $locked, ['name' => $locked->name], actor: $by);
            ActivityLog::record($locked->project, $by, "Cancelled the deliverable {$locked->name}");
        });
    }

    /**
     * Runs a change on the locked row, if it is still in a state that allows it.
     *
     * @param  list<string>  $from
     * @param  callable(Deliverable): void  $change
     *
     * @throws ValidationException
     */
    private static function step(Deliverable $deliverable, array $from, callable $change): void
    {
        DB::transaction(function () use ($deliverable, $from, $change) {
            $locked = Deliverable::query()->lockForUpdate()->whereKey($deliverable->id)->firstOrFail();
            self::ensureOpen($locked->project);
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['deliverable' => "{$locked->name} is already {$locked->status}."]);
            }
            $change($locked);
        });
    }

    /**
     * @throws ValidationException
     */
    private static function ensureOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['deliverable' => "{$project->code} is closed."]);
        }
    }
}
