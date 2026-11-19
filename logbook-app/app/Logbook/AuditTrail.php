<?php

namespace App\Logbook;

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who did what in Logbook. Called inside the change's transaction, so an
 * event exists exactly when the change does. Append-only (a PostgreSQL
 * trigger refuses updates and deletes); no route changes it. Details hold
 * names and statuses, never values or files.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, Model $subject, ?User $user, array $details = []): AuditEvent
    {
        [$type, $label, $logbookId] = match (true) {
            $subject instanceof Logbook => ['logbook', $subject->name, $subject->id],
            $subject instanceof LogSheet => ['log_sheet', $subject->code.' · '.$subject->name, $subject->logbook_id],
            $subject instanceof Entry => ['entry', ($subject->reference ?? 'Draft').' · '.$subject->sheet->name, $subject->logbook_id],
            default => throw new InvalidArgumentException('Logbook does not audit '.$subject::class),
        };

        return AuditEvent::query()->create([
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'subject_label' => mb_substr($label, 0, 255),
            'logbook_id' => $logbookId,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'details' => array_filter($details, fn ($value) => $value !== null) ?: null,
        ]);
    }
}
