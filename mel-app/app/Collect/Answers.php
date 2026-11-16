<?php

namespace App\Collect;

use App\Collect\Models\Attachment;
use App\Collect\Models\Submission;

/**
 * A collected submission's answers as people read them, always rendered by
 * the submission's own form version (ADR-0012 §10). Answers to personal
 * questions (marked personal, photos, places) are withheld, on the server,
 * from anyone without mel.collect.personal_data: they never reach the page.
 */
final class Answers
{
    public const HIDDEN = 'Hidden (personal data)';

    /**
     * @return list<array{name: string, label: string, type: string, group: string|null, personal: bool, hidden: bool, value: string|null, original: string|null, changed: bool, error: string|null, attachmentId: string|null}>
     */
    public static function present(Submission $submission, bool $seesPersonal): array
    {
        $definition = $submission->version->definition;
        $current = $submission->currentAnswers();
        $original = $submission->answers;
        $revised = $submission->current_revision_id !== null;
        $errors = $submission->purged_at === null
            ? Rules::evaluate($definition, $current, $submission->completed_at->toDateString())['errors']
            : [];
        $files = $submission->relationLoaded('attachments') ? $submission->attachments : $submission->attachments()->get();

        $rows = [];
        foreach (Definition::items($definition) as ['item' => $item, 'groups' => $groups]) {
            $type = (string) ($item['type'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if (! in_array($type, Definition::ANSWERABLE, true)) {
                continue;
            }
            $personal = Definition::isPersonal($item);
            $hidden = $personal && ! $seesPersonal;
            $value = $current[$name] ?? null;
            $before = $original[$name] ?? null;
            $attachment = $type === 'photo' && ! $hidden ? $files->first(fn (Attachment $file) => $file->question === $name) : null;

            $rows[] = [
                'name' => $name,
                'label' => is_string($item['label'] ?? null) ? $item['label'] : $name,
                'type' => $type,
                'group' => $groups === [] ? null : end($groups),
                'personal' => $personal,
                'hidden' => $hidden,
                'value' => $hidden ? null : self::text($definition, $item, $value),
                'original' => $hidden || ! $revised ? null : self::text($definition, $item, $before),
                'changed' => $revised && Submissions::changed([$name => $before], [$name => $value]) !== [],
                'error' => $errors[$name] ?? null,
                'attachmentId' => $attachment?->id,
            ];
        }

        return $rows;
    }

    /**
     * One answer as text: choice labels, "lat, lng (± m)", dates as given.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $question
     */
    public static function text(array $definition, array $question, mixed $value): ?string
    {
        if (Rules::isEmpty($value)) {
            return null;
        }

        return match ($question['type'] ?? null) {
            'select_one', 'yes_no' => Definition::choices($definition, $question)[$value] ?? (is_scalar($value) ? (string) $value : null),
            'select_multiple' => is_array($value)
                ? implode('; ', array_map(fn ($choice) => Definition::choices($definition, $question)[$choice] ?? (string) $choice, $value))
                : null,
            'geopoint' => is_array($value)
                ? sprintf('%s, %s', $value['lat'] ?? '?', $value['lng'] ?? '?').(isset($value['accuracy']) ? sprintf(' (± %s m)', round((float) $value['accuracy'])) : '')
                : null,
            'photo' => 'Photo',
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }
}
