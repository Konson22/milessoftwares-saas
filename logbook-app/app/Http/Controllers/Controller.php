<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Entries;
use App\Logbook\Fields;
use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    protected function user(): User
    {
        $user = request()->user();
        assert($user instanceof User);

        return $user;
    }

    protected static function time(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    /**
     * An entry in lists.
     *
     * @return array<string, mixed>
     */
    protected static function entryRow(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'reviewRequired' => $entry->review_required,
            'occurredAt' => self::time($entry->occurred_at),
            'submittedAt' => self::time($entry->submitted_at),
            'logbook' => $entry->logbook->name,
            'sheet' => ['id' => $entry->log_sheet_id, 'name' => $entry->sheet->name, 'code' => $entry->sheet->code],
            'creator' => $entry->creator->name,
            'summary' => self::summary($entry),
            // Readings outside their normal range: the list flags the entry.
            'outOfRange' => count(Fields::deviations($entry->version->fields, $entry->values ?? [])),
        ];
    }

    /**
     * The first few answers, as text: what the entry is about.
     */
    protected static function summary(Entry $entry): string
    {
        $parts = [];
        // Resource names, looked up once per request for a whole list.
        /** @var array<string, string|null> $resources */
        $resources = request()->attributes->get('logbook.resource_labels', []);
        $wanted = [];
        foreach ($entry->version->fields as $field) {
            $value = $entry->values[$field['name']] ?? null;
            if ($field['type'] === 'resource' && is_string($value) && ! array_key_exists($value, $resources)) {
                $wanted[] = $value;
            }
        }
        if ($wanted !== []) {
            $found = LogResource::query()->whereIn('id', $wanted)->get();
            foreach ($wanted as $id) {
                $resources[$id] = $found->firstWhere('id', $id)?->label();
            }
            request()->attributes->set('logbook.resource_labels', $resources);
        }
        $names = array_filter($resources, fn ($label) => $label !== null);
        foreach ($entry->version->fields as $field) {
            if (in_array($field['type'], ['file', 'user', 'yes_no'], true)) {
                continue;
            }
            $text = Fields::text($field, $entry->values[$field['name']] ?? null, $names);
            if ($text !== null) {
                $parts[] = $text;
            }
            if (count($parts) === 3) {
                break;
            }
        }

        return mb_strimwidth(implode(' · ', $parts), 0, 160, '…');
    }

    /**
     * The fields of a version with an entry's values, ready to read.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  Collection<int, Attachment>  $files
     * @return list<array<string, mixed>>
     */
    protected static function answers(array $fields, array $values, Collection $files): array
    {
        $people = Entries::people($fields, $values);
        $names = $files->pluck('name', 'id')->all();
        $deviations = array_column(Fields::deviations($fields, $values), null, 'name');

        return array_map(fn (array $field) => [
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'text' => Fields::text($field, $values[$field['name']] ?? null, $people, $names),
            'unit' => $field['unit'] ?? null,
            // A reading outside the field's normal range: recorded, and flagged here.
            'outOfRange' => isset($deviations[$field['name']])
                ? ['direction' => $deviations[$field['name']]['direction'], 'range' => $deviations[$field['name']]['range']]
                : null,
            'files' => $field['type'] === 'file'
                ? $files->whereIn('id', (array) ($values[$field['name']] ?? []))->map(fn (Attachment $a) => [
                    'id' => $a->id, 'name' => $a->name, 'size' => $a->size, 'mime' => $a->mime, 'removed' => $a->removed_at !== null,
                ])->values()->all()
                : [],
        ], $fields);
    }

    /**
     * Sheets the member may write on now, for "new entry" pickers.
     *
     * @return list<array{id: string, name: string, code: string, logbook: string}>
     */
    protected static function writableSheets(): array
    {
        if (! Gate::allows('logbook.entries.create')) {
            return [];
        }

        return array_values(Access::writableSheets()->with('logbook:id,name')->orderBy('name')->get()
            ->map(fn (LogSheet $s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'logbook' => $s->logbook->name])
            ->all());
    }

    /**
     * Active resources for resource fields (ADR-0026 §2.10).
     *
     * @return list<array{value: string, label: string, type: string}>
     */
    protected static function resourceOptions(): array
    {
        return array_values(LogResource::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'type'])
            ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label(), 'type' => $r->type])->all());
    }

    /**
     * Filter choices: logbooks and sheets the member reaches.
     *
     * @return array{logbooks: list<array{value: string, label: string}>, sheets: list<array{value: string, label: string, logbook: string}>}
     */
    protected static function filterOptions(): array
    {
        return [
            'logbooks' => array_values(Access::logbooks()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Logbook $l) => ['value' => $l->id, 'label' => $l->name])->all()),
            'sheets' => array_values(Access::sheets()->whereNotNull('published_version_id')->orderBy('name')->get(['id', 'name', 'code', 'logbook_id'])
                ->map(fn (LogSheet $s) => ['value' => $s->id, 'label' => "{$s->code} · {$s->name}", 'logbook' => $s->logbook_id])->all()),
        ];
    }
}
