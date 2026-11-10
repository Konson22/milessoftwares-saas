<?php

namespace App\Finance;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The books' audit trail: control events, with who, what and before/after.
 * Called inside the same transaction as the change, so an event is written
 * exactly when the change is.
 *
 * Role assignments are not here: they are made, and audited, on the platform.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?User $by = null): AuditEvent
    {
        $actor = $by ?? Auth::user();

        return AuditEvent::query()->create([
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject === null ? null : $this->label($subject),
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function label(Model $subject): ?string
    {
        foreach (['number', 'name', 'code'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }
}
