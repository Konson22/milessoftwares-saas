<?php

namespace App\Collect;

/**
 * What must hold before a definition is published (ADR-0012 §4). The
 * builder shows these issues as the draft is saved; publishing refuses a
 * definition with any.
 *
 * Every rule may refer only to questions *earlier* in the form, so logic
 * can never go round in a circle, and an answer a rule depends on is
 * always settled before the rule is evaluated.
 */
final class DefinitionCheck
{
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private const CHOICE_NAME = '/^[A-Za-z0-9_.-]{1,64}$/';

    private const ITEM_KEYS = ['type', 'name', 'label', 'hint', 'required', 'required_if', 'relevant', 'constraint', 'choices', 'personal', 'items'];

    private const CONSTRAINTS = [
        'text' => ['min_length', 'max_length', 'pattern'],
        'textarea' => ['min_length', 'max_length', 'pattern'],
        'integer' => ['min', 'max'],
        'decimal' => ['min', 'max'],
        'date' => ['min', 'max'],
        'datetime' => ['min', 'max'],
        'time' => ['min', 'max'],
        'select_multiple' => ['min_choices', 'max_choices'],
    ];

    private const MAX_CONDITION_DEPTH = 5;

    private const MAX_CONDITION_LEAVES = 20;

    /** @var list<array{path: string, question: string|null, message: string}> */
    private array $issues = [];

    /** @var array<string, array<string, mixed>> Questions declared so far, by name. */
    private array $earlier = [];

    /** @var array<string, true> Every name seen so far. */
    private array $names = [];

    /** @var array<string, true> Every name in the form, before or after. */
    private array $allNames = [];

    /** @var array<array-key, mixed> As decoded: a list name like "1" arrives as an integer key. */
    private array $choices = [];

    private int $count = 0;

    /**
     * @return list<array{path: string, question: string|null, message: string}>
     */
    public static function issues(mixed $definition): array
    {
        $check = new self;
        $check->run($definition);

        return $check->issues;
    }

    private function run(mixed $definition): void
    {
        if (! is_array($definition) || array_is_list($definition)) {
            $this->issue('', null, 'The form definition is not readable.');

            return;
        }

        if (strlen((string) json_encode($definition)) > Definition::MAX_BYTES) {
            $this->issue('', null, 'The form is too large (more than 1 MB).');

            return;
        }

        if (($definition['schema_version'] ?? null) !== Definition::SCHEMA_VERSION) {
            $this->issue('schema_version', null, 'This form was made for another version of M&E.');
        }

        $settings = $definition['settings'] ?? null;
        if (! is_array($settings) || ! $this->isText($settings['title'] ?? null, 200)) {
            $this->issue('settings.title', null, 'Give the form a title (up to 200 characters).');
        }
        if (is_array($settings) && array_key_exists('consent', $settings)
            && (! is_array($settings['consent']) || ! $this->isText($settings['consent']['text'] ?? null, 5000))) {
            $this->issue('settings.consent', null, 'Write the consent statement (up to 5,000 characters), or remove it.');
        }

        $this->choices = is_array($definition['choices'] ?? null) ? $definition['choices'] : [];
        $this->checkChoiceLists();

        $items = $definition['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            $this->issue('items', null, 'The form has no list of questions.');

            return;
        }

        foreach (Definition::items($definition) as ['item' => $item]) {
            if (is_string($item['name'] ?? null)) {
                $this->allNames[$item['name']] = true;
            }
        }

        $this->checkItems($items, 'items', 0);

        if ($this->count > Definition::MAX_ITEMS) {
            $this->issue('items', null, 'A form holds at most '.Definition::MAX_ITEMS.' questions and sections.');
        }
        if ($this->earlier === []) {
            $this->issue('items', null, 'Add at least one question.');
        }
    }

    private function checkChoiceLists(): void
    {
        if ($this->choices !== [] && array_is_list($this->choices)) {
            $this->issue('choices', null, 'The choice lists are not readable.');
            $this->choices = [];

            return;
        }

        foreach ($this->choices as $list => $choices) {
            $path = "choices.{$list}";
            if (! is_array($choices) || ! array_is_list($choices) || $choices === []) {
                $this->issue($path, null, "The choice list \"{$list}\" has no choices.");

                continue;
            }
            $seen = [];
            foreach ($choices as $i => $choice) {
                $name = is_array($choice) ? ($choice['name'] ?? null) : null;
                if (! is_string($name) || preg_match(self::CHOICE_NAME, $name) !== 1) {
                    $this->issue("{$path}.{$i}.name", null, 'A choice value uses letters, digits, "_", "." and "-" only (up to 64).');
                } elseif (isset($seen[$name])) {
                    $this->issue("{$path}.{$i}.name", null, "The value \"{$name}\" appears twice in the list \"{$list}\".");
                } else {
                    $seen[$name] = true;
                }
                if (! is_array($choice) || ! $this->isText($choice['label'] ?? null, 500)) {
                    $this->issue("{$path}.{$i}.label", null, 'Every choice needs a label.');
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $items
     */
    private function checkItems(array $items, string $path, int $depth): void
    {
        foreach ($items as $i => $item) {
            $this->checkItem($item, "{$path}.{$i}", $depth);
        }
    }

    private function checkItem(mixed $item, string $path, int $depth): void
    {
        $this->count++;

        if (! is_array($item) || array_is_list($item)) {
            $this->issue($path, null, 'This item is not readable.');

            return;
        }

        $type = $item['type'] ?? null;
        $name = is_string($item['name'] ?? null) ? $item['name'] : null;

        if ($name === null || preg_match(self::NAME, $name) !== 1) {
            $this->issue("{$path}.name", $name, 'A name starts with a letter or "_" and uses letters, digits and "_" only (up to 64).');
        } elseif (isset($this->names[$name])) {
            $this->issue("{$path}.name", $name, "The name \"{$name}\" is used twice. Names must be unique in the form.");
        } else {
            $this->names[$name] = true;
        }

        if (in_array($type, Definition::LATER, true)) {
            $this->issue("{$path}.type", $name, ucfirst((string) $type).' questions are not available yet.');

            return;
        }
        if (! in_array($type, [...Definition::ANSWERABLE, ...Definition::DISPLAY], true)) {
            $this->issue("{$path}.type", $name, 'Unknown question type.');

            return;
        }

        foreach (array_keys($item) as $key) {
            if (! in_array($key, self::ITEM_KEYS, true)) {
                $this->issue("{$path}.{$key}", $name, "Unknown property \"{$key}\".");
            }
        }

        if (! $this->isText($item['label'] ?? null, $type === 'note' ? 5000 : 500)) {
            $this->issue("{$path}.label", $name, $type === 'note' ? 'Write the note.' : 'Every question needs a label.');
        }
        if (array_key_exists('hint', $item) && ! ($item['hint'] === null || ($item['hint'] === '') || $this->isText($item['hint'], 1000))) {
            $this->issue("{$path}.hint", $name, 'Help text is up to 1,000 characters.');
        }
        if (array_key_exists('personal', $item) && ! is_bool($item['personal'])) {
            $this->issue("{$path}.personal", $name, 'Not readable.');
        }

        // Rules on the item refer to questions before it.
        if (array_key_exists('relevant', $item)) {
            $this->checkCondition($item['relevant'], "{$path}.relevant", $name);
        }

        if ($type === 'group') {
            foreach (['required', 'required_if', 'constraint', 'choices'] as $key) {
                if (array_key_exists($key, $item)) {
                    $this->issue("{$path}.{$key}", $name, 'A section has no answer of its own.');
                }
            }
            if ($depth >= 3) {
                $this->issue($path, $name, 'Sections nest at most three deep.');
            }
            if (! is_array($item['items'] ?? null) || ! array_is_list($item['items']) || $item['items'] === []) {
                $this->issue("{$path}.items", $name, 'This section is empty.');

                return;
            }
            $this->checkItems($item['items'], "{$path}.items", $depth + 1);

            return;
        }

        if (array_key_exists('items', $item)) {
            $this->issue("{$path}.items", $name, 'Only sections hold questions.');
        }

        if ($type === 'note') {
            foreach (['required', 'required_if', 'constraint', 'choices'] as $key) {
                if (array_key_exists($key, $item)) {
                    $this->issue("{$path}.{$key}", $name, 'A note has no answer.');
                }
            }

            return;
        }

        if (array_key_exists('required', $item) && ! is_bool($item['required'])) {
            $this->issue("{$path}.required", $name, 'Not readable.');
        }
        if (array_key_exists('required_if', $item)) {
            $this->checkCondition($item['required_if'], "{$path}.required_if", $name);
        }

        if (in_array($type, ['select_one', 'select_multiple'], true)) {
            $list = $item['choices'] ?? null;
            if (! is_string($list) || ! is_array($this->choices[$list] ?? null) || $this->choices[$list] === []) {
                $this->issue("{$path}.choices", $name, 'Give the question its choices.');
            }
        } elseif (array_key_exists('choices', $item)) {
            $this->issue("{$path}.choices", $name, 'Only choice questions have choices.');
        }

        $this->checkConstraint($item, $type, $path, $name);

        if ($name !== null) {
            // Declared only now: a question's own rules cannot refer to it.
            $this->earlier[$name] = $item;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function checkConstraint(array $item, string $type, string $path, ?string $name): void
    {
        if (! array_key_exists('constraint', $item)) {
            return;
        }

        $constraint = $item['constraint'];
        $path = "{$path}.constraint";
        $allowed = self::CONSTRAINTS[$type] ?? [];

        if (! is_array($constraint) || ($constraint !== [] && array_is_list($constraint))) {
            $this->issue($path, $name, 'Not readable.');

            return;
        }

        foreach ($constraint as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                $this->issue("{$path}.{$key}", $name, "\"{$key}\" does not apply to this kind of question.");

                continue;
            }

            $ok = match ($key) {
                'min', 'max' => match ($type) {
                    'integer' => is_int($value),
                    'decimal' => is_int($value) || is_float($value),
                    'date', 'datetime' => is_string($value) && Rules::bound($value, '2000-01-01') !== null,
                    'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1,
                    default => false,
                },
                'min_length', 'max_length' => is_int($value) && $value >= 0 && $value <= Definition::TEXTAREA_MAX,
                'min_choices', 'max_choices' => is_int($value) && $value >= 0,
                'pattern' => is_string($value) && array_key_exists($value, Rules::PATTERNS),
            };

            if (! $ok) {
                $this->issue("{$path}.{$key}", $name, match ($key) {
                    'min', 'max' => match ($type) {
                        'integer' => 'Use a whole number.',
                        'decimal' => 'Use a number.',
                        'date', 'datetime' => 'Use a date, "today", or a number of days from today.',
                        default => 'Use a time such as 08:30.',
                    },
                    'pattern' => 'Choose a format from the list.',
                    default => 'Use a whole number of 0 or more.',
                });
            }
        }

        foreach ([['min', 'max'], ['min_length', 'max_length'], ['min_choices', 'max_choices']] as [$low, $high]) {
            $a = $constraint[$low] ?? null;
            $b = $constraint[$high] ?? null;
            if ($a === null || $b === null) {
                continue;
            }
            // Relative dates compare on a fixed day: "today-7d" is below "today".
            $a = in_array($type, ['date', 'datetime'], true) ? Rules::bound($a, '2000-01-01') : $a;
            $b = in_array($type, ['date', 'datetime'], true) ? Rules::bound($b, '2000-01-01') : $b;
            if ($a !== null && $b !== null && $a > $b) {
                $this->issue("{$path}.{$low}", $name, 'The lowest allowed is above the highest allowed.');
            }
        }
    }

    private function checkCondition(mixed $condition, string $path, ?string $owner): void
    {
        $leaves = 0;
        $this->checkPart($condition, $path, $owner, 1, $leaves);

        if ($leaves > self::MAX_CONDITION_LEAVES) {
            $this->issue($path, $owner, 'A rule compares at most '.self::MAX_CONDITION_LEAVES.' answers.');
        }
    }

    private function checkPart(mixed $condition, string $path, ?string $owner, int $depth, int &$leaves): void
    {
        if ($depth > self::MAX_CONDITION_DEPTH) {
            $this->issue($path, $owner, 'A rule nests at most '.self::MAX_CONDITION_DEPTH.' deep.');

            return;
        }

        if (! is_array($condition) || array_is_list($condition)) {
            $this->issue($path, $owner, 'The rule is not readable.');

            return;
        }

        foreach (['all', 'any'] as $join) {
            if (array_key_exists($join, $condition)) {
                if (count($condition) !== 1 || ! is_array($condition[$join]) || ! array_is_list($condition[$join]) || $condition[$join] === []) {
                    $this->issue($path, $owner, 'The rule is not readable.');

                    return;
                }
                foreach ($condition[$join] as $i => $part) {
                    $this->checkPart($part, "{$path}.{$join}.{$i}", $owner, $depth + 1, $leaves);
                }

                return;
            }
        }

        if (array_key_exists('not', $condition)) {
            if (count($condition) !== 1) {
                $this->issue($path, $owner, 'The rule is not readable.');

                return;
            }
            $this->checkPart($condition['not'], "{$path}.not", $owner, $depth + 1, $leaves);

            return;
        }

        $leaves++;
        $this->checkLeaf($condition, $path, $owner);
    }

    /**
     * @param  array<string, mixed>  $leaf
     */
    private function checkLeaf(array $leaf, string $path, ?string $owner): void
    {
        $q = $leaf['q'] ?? null;
        $op = $leaf['op'] ?? null;

        foreach (array_keys($leaf) as $key) {
            if (! in_array($key, ['q', 'op', 'value'], true)) {
                $this->issue($path, $owner, 'The rule is not readable.');

                return;
            }
        }

        if (! is_string($q) || ! isset($this->earlier[$q])) {
            $this->issue("{$path}.q", $owner, is_string($q) && isset($this->allNames[$q])
                ? "The rule refers to \"{$q}\", which is not an earlier question. Rules can only use answers given before."
                : 'The rule refers to a question that does not exist.');

            return;
        }

        $question = $this->earlier[$q];
        $type = (string) $question['type'];
        $value = $leaf['value'] ?? null;
        $hasValue = array_key_exists('value', $leaf);

        if (in_array($op, ['answered', 'not_answered'], true)) {
            if ($hasValue) {
                $this->issue("{$path}.value", $owner, '"Is answered" compares with nothing.');
            }

            return;
        }

        $choices = in_array($type, ['select_one', 'select_multiple', 'yes_no'], true)
            ? array_keys(Definition::choices(['choices' => $this->choices], $question))
            : [];
        $isChoice = fn (mixed $v) => is_string($v) && in_array($v, $choices, true);
        $isDateLike = fn (mixed $v) => is_string($v) && match ($type) {
            'date' => Rules::isDate($v),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1,
            'datetime' => strlen($v) === 16 && $v[10] === 'T' && Rules::isDate(substr($v, 0, 10)) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr($v, 11)) === 1,
            default => false,
        };
        $isNumber = fn (mixed $v) => is_int($v) || is_float($v);

        $ok = match ($op) {
            'eq', 'ne' => match ($type) {
                'text', 'textarea' => is_string($value),
                'integer', 'decimal' => $isNumber($value),
                'select_one', 'yes_no' => $isChoice($value),
                'date', 'time', 'datetime' => $isDateLike($value),
                default => null,
            },
            'gt', 'gte', 'lt', 'lte' => match ($type) {
                'integer', 'decimal' => $isNumber($value),
                'date', 'time', 'datetime' => $isDateLike($value),
                default => null,
            },
            'includes' => $type === 'select_multiple' ? $isChoice($value) : null,
            'in' => in_array($type, ['select_one', 'yes_no'], true)
                ? is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, fn ($v) => ! $isChoice($v)) === []
                : null,
            default => 'op',
        };

        if ($ok === 'op') {
            $this->issue("{$path}.op", $owner, 'Unknown comparison.');
        } elseif ($ok === null) {
            $this->issue("{$path}.op", $owner, "This comparison does not apply to \"{$q}\".");
        } elseif ($ok === false) {
            $this->issue("{$path}.value", $owner, $choices !== [] ? "Compare \"{$q}\" with one of its choices." : "The value does not suit \"{$q}\".");
        }
    }

    private function isText(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max;
    }

    private function issue(string $path, ?string $question, string $message): void
    {
        $this->issues[] = ['path' => $path, 'question' => $question, 'message' => $message];
    }
}
