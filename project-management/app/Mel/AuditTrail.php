<?php

namespace App\Mel;

use App\Collect\Models\Assignment as CollectAssignment;
use App\Collect\Models\Export as CollectExport;
use App\Collect\Models\Form as CollectForm;
use App\Collect\Models\Submission as CollectSubmission;
use App\Mel\Models\AuditEvent;
use App\Mel\Models\Dimension;
use App\Mel\Models\ImportBatch;
use App\Mel\Models\Indicator;
use App\Mel\Models\Location;
use App\Mel\Models\Partner;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Result;
use App\Mel\Models\Settings;
use App\Mel\Models\Submission;
use App\Models\User;
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
            $subject instanceof CollectForm => ['form', $subject->name],
            $subject instanceof CollectSubmission => ['collected', $subject->label()],
            $subject instanceof CollectAssignment => ['assignment', $subject->user->name.' · '.$subject->form->name],
            $subject instanceof CollectExport => ['export', $subject->form->name.' · '.strtoupper($subject->format)],
            $subject instanceof Settings => ['settings', 'M&E settings'],
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
