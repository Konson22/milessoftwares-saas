<?php

namespace App\Petrol;

use App\Models\Shift;
use App\Models\ShiftException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exceptions stay on the shift after the reading or the cash is corrected.
 */
class ShiftExceptions
{
    public function __construct(private readonly Audit $audit) {}

    public function open(Shift $shift, ?string $assignmentId, ?string $handoverId, string $type, string $description, string $severity, User $by, ?string $sourceType = null, ?string $sourceId = null): ShiftException
    {
        if ($sourceId !== null) {
            $existing = ShiftException::query()
                ->where('shift_id', $shift->id)
                ->where('type', $type)
                ->where('source_id', $sourceId)
                ->whereIn('status', [ShiftException::OPEN, ShiftException::UNDER_REVIEW])
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $row = ShiftException::query()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'assignment_id' => $assignmentId,
            'handover_id' => $handoverId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'type' => $type,
            'description' => mb_substr(trim($description), 0, 250),
            'severity' => $severity,
            'status' => ShiftException::OPEN,
            'created_by' => $by->id,
        ]);
        $this->audit->record($by, 'exception.opened', $row, null, ['type' => $type, 'severity' => $severity], $shift->branch_id);

        return $row;
    }

    public function review(ShiftException $exception, User $by): ShiftException
    {
        return $this->move($exception, $by, [ShiftException::OPEN], ShiftException::UNDER_REVIEW, 'exception.reviewed', [
            'reviewer_id' => $by->id,
        ]);
    }

    public function resolve(ShiftException $exception, User $by, string $resolution): ShiftException
    {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw ValidationException::withMessages(['resolution' => 'Say how this was resolved.']);
        }

        return $this->move($exception, $by, [ShiftException::OPEN, ShiftException::UNDER_REVIEW], ShiftException::RESOLVED, 'exception.resolved', [
            'resolution' => $resolution,
            'resolved_by' => $by->id,
            'resolved_at' => now(),
        ]);
    }

    public function close(ShiftException $exception, User $by): ShiftException
    {
        return $this->move($exception, $by, [ShiftException::RESOLVED], ShiftException::CLOSED, 'exception.closed', [
            'closed_by' => $by->id,
            'closed_at' => now(),
        ]);
    }

    public function reopen(ShiftException $exception, User $by, string $reason): ShiftException
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }

        return $this->move($exception, $by, [ShiftException::RESOLVED, ShiftException::CLOSED], ShiftException::OPEN, 'exception.reopened', [
            'resolution' => $reason,
            'resolved_by' => null,
            'resolved_at' => null,
            'closed_by' => null,
            'closed_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(ShiftException $exception, User $by, array $from, string $to, string $action, array $extra): ShiftException
    {
        return DB::transaction(function () use ($exception, $by, $from, $to, $action, $extra) {
            $locked = ShiftException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['exception' => 'This exception is '.$locked->status.'.']);
            }
            $before = $locked->status;
            $locked->forceFill(['status' => $to] + $extra)->save();
            $this->audit->record($by, $action, $locked, ['status' => $before], ['status' => $to] + $extra, $locked->branch_id);

            return $locked;
        });
    }
}
