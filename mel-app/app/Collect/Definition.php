<?php

namespace App\Collect;

/**
 * The form definition, schema_version 1 (ADR-0012 §3): XLSForm's
 * vocabulary as JSON.
 *
 *   { "schema_version": 1,
 *     "settings": { "title": "…", "consent": { "text": "…" } },
 *     "items": [ { "type": "integer", "name": "members", "label": "…", "required": true,
 *                  "constraint": { "min": 1 }, "relevant": { "q": "…", "op": "eq", "value": "…" } },
 *                { "type": "group", "name": "…", "label": "…", "items": [ … ] } ],
 *     "choices": { "list_name": [ { "name": "…", "label": "…" } ] } }
 *
 * Question names are the stable identifiers: rules and answers use them,
 * never labels. resources/js/collect/definition.ts holds the same shapes.
 */
final class Definition
{
    public const SCHEMA_VERSION = 1;

    /** Questions that hold an answer. */
    public const ANSWERABLE = [
        'text', 'textarea', 'integer', 'decimal', 'select_one', 'select_multiple', 'yes_no',
        'date', 'time', 'datetime', 'geopoint', 'photo',
    ];

    /** Shown, never answered. */
    public const DISPLAY = ['note', 'group'];

    /** Named now so a definition never reuses them; refused until built. */
    public const LATER = ['audio', 'video', 'signature', 'barcode', 'repeat', 'matrix', 'rank', 'calculate', 'geotrace', 'geoshape'];

    /** Always personal data, whatever the form says: photos and exact places. */
    public const ALWAYS_PERSONAL = ['photo', 'geopoint'];

    public const MAX_ITEMS = 500;

    public const MAX_BYTES = 1_048_576;

    /** A text answer is at most this long unless the question says less. */
    public const TEXT_MAX = 1000;

    public const TEXTAREA_MAX = 10000;

    /**
     * An empty definition for a new form.
     *
     * @return array<string, mixed>
     */
    public static function blank(string $title): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'settings' => ['title' => $title],
            'items' => [],
            'choices' => (object) [],
        ];
    }

    /**
     * Every item in document order (groups before their contents), with
     * the names of the groups it sits in.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{item: array<string, mixed>, groups: list<string>}>
     */
    public static function items(array $definition): array
    {
        $out = [];
        $walk = function (mixed $items, array $groups) use (&$walk, &$out): void {
            if (! is_array($items)) {
                return;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $out[] = ['item' => $item, 'groups' => $groups];
                if (($item['type'] ?? null) === 'group') {
                    $walk($item['items'] ?? [], [...$groups, (string) ($item['name'] ?? '')]);
                }
            }
        };
        $walk($definition['items'] ?? [], []);

        return $out;
    }

    /**
     * The questions that hold an answer, by name, in document order.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    public static function questions(array $definition): array
    {
        $questions = [];
        foreach (self::items($definition) as ['item' => $item]) {
            if (in_array($item['type'] ?? null, self::ANSWERABLE, true) && is_string($item['name'] ?? null)) {
                $questions[$item['name']] = $item;
            }
        }

        return $questions;
    }

    /**
     * Whether answers to the question are personal data.
     *
     * @param  array<string, mixed>  $question
     */
    public static function isPersonal(array $question): bool
    {
        return in_array($question['type'] ?? null, self::ALWAYS_PERSONAL, true) || ($question['personal'] ?? false) === true;
    }

    /**
     * The choices of a select question: [name => label].
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $question
     * @return array<string, string>
     */
    public static function choices(array $definition, array $question): array
    {
        if (($question['type'] ?? null) === 'yes_no') {
            return ['yes' => 'Yes', 'no' => 'No'];
        }

        $list = $definition['choices'][$question['choices'] ?? ''] ?? [];
        $choices = [];
        foreach (is_array($list) ? $list : [] as $choice) {
            if (is_array($choice) && is_string($choice['name'] ?? null)) {
                $choices[$choice['name']] = is_string($choice['label'] ?? null) ? $choice['label'] : $choice['name'];
            }
        }

        return $choices;
    }
}
