<?php

namespace App\Mel;

use App\Models\AuditEvent;
use App\Models\Dimension;
use App\Models\ImportBatch;
use App\Models\Indicator;
use App\Models\Location;
use App\Models\Partner;
use App\Models\Programme;
use App\Models\ProgrammeUnit;
use App\Models\ReportingPeriod;
use App\Models\Result;
use App\Models\Submission;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Records who did what in M&E. Called inside the change's transaction, so
 * an entry exists exactly when the change does.
 */
class AuditTrail
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $subject, ?User $user, array $details = []): AuditEvent
    {
        [$type, $label] = match (true) {
            $subject instanceof Programme => ['programme', $subject->label()],
            $subject instanceof ProgrammeUnit => ['unit', $subject->label()],
            $subject instanceof ReportingPeriod => ['period', $subject->label],
            $subject instanceof Result => ['result', $subject->label()],
            $subject instanceof Indicator => ['indicator', $subject->code],
            $subject instanceof Submission => ['submission', $subject->indicator->code.' · '.$subject->period->label.' · '.$subject->unit->code],
            $subject instanceof ImportBatch => ['import', $subject->file_name],
            $subject instanceof Location => ['location', $subject->name],
            $subject instanceof Partner => ['partner', $subject->name],
            $subject instanceof Dimension => ['dimension', $subject->name],
            $subject instanceof Workspace => ['settings', 'M&E settings'],
            default => throw new InvalidArgumentException('M&E does not audit '.$subject::class),
        };

        return AuditEvent::query()->create([
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => mb_substr($label, 0, 255),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'details' => array_filter($details, fn ($value) => $value !== null) ?: null,
        ]);
    }
}
