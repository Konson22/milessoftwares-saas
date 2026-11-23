<?php

namespace App\Logbook;

use Illuminate\Support\Str;

/**
 * The log sheet field engine (ADR-0025 §2.3). A template is a list of
 * fields; a field's `name` is its stable identifier and entry values are
 * keyed by it. Type names follow M&E Collect's vocabulary.
 *
 *   { "name": "fuel_litres", "label": "Fuel consumed (litres)", "type": "decimal",
 *     "required": true, "min": 0, "help": "…", "placeholder": "…", "default": … }
 *
 * A number's `min` and `max` are hard limits: a value outside them is
 * refused. Its `expected_min` and `expected_max` are the normal range: a
 * reading outside it is still recorded (that is what the log is for) but is
 * flagged as out of range for the reviewer. `unit` is shown after the value.
 *
 * Conditional fields (ADR-0026 §2.9): show_if {field, in: [values]}
 * names an EARLIER yes/no or choice field (so there are no cycles). A
 * hidden field is not required and its value is cleared on save. Exception
 * rules: a number with raise_exception (outside its normal range), or a
 * yes/no or single choice whose answer is in exception_values, opens an
 * exception on submission (App\Logbook\Exceptions::fromSubmission).
 *
 * Definitions are data: nothing in them is ever executed. Every value is
 * checked here, on the server, against the entry's own template version.
 */
final class Fields
{
    public const TYPES = [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'integer' => 'Whole number',
        'decimal' => 'Decimal number',
        'date' => 'Date',
        'time' => 'Time',
        'datetime' => 'Date and time',
        'select_one' => 'Single choice',
        'select_multiple' => 'Multiple choice',
        'yes_no' => 'Yes / No',
        'file' => 'File or photo',
        'user' => 'Person',
        'resource' => 'Resource',
    ];

    /** Types a later field's show_if can name (ADR-0026 §2.9). */
    public const CONDITIONAL = ['yes_no', 'select_one', 'select_multiple'];

    /** The settings each type takes; anything else is dropped. */
    public const SETTINGS = [
        'text' => ['placeholder', 'max_length', 'default'],
        'textarea' => ['placeholder', 'max_length', 'default'],
        'integer' => ['placeholder', 'min', 'max', 'expected_min', 'expected_max', 'unit', 'default', 'raise_exception', 'exception_severity', 'summable'],
        'decimal' => ['placeholder', 'min', 'max', 'expected_min', 'expected_max', 'unit', 'default', 'raise_exception', 'exception_severity', 'summable'],
        'date' => [],
        'time' => [],
        'datetime' => [],
        'select_one' => ['choices', 'default', 'exception_values', 'exception_severity'],
        'select_multiple' => ['choices'],
        'yes_no' => ['default', 'exception_values', 'exception_severity'],
        'file' => [],
        'user' => [],
        'resource' => ['resource_type'],
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const NUMERIC = ['integer', 'decimal'];

    /** Units whose readings never add up: a total of temperatures means nothing (ADR-0031 §11). */
    public const LEVEL_UNITS = ['°c', '°f', 'c', 'f', 'k', '%', 'bar', 'psi', 'kpa', 'ph', 'v', 'a', 'hz', 'rpm'];

    /**
     * Whether a number field's readings may be summed: as the builder
     * set it, otherwise not for a level (temperature, pressure, a
     * percentage), yes for anything else.
     *
     * @param  array<string, mixed>  $field
     */
    public static function summable(array $field): bool
    {
        if (is_bool($field['summable'] ?? null)) {
            return $field['summable'];
        }

        return ! in_array(mb_strtolower(trim((string) ($field['unit'] ?? ''))), self::LEVEL_UNITS, true);
    }

    public const MAX_FIELDS = 60;

    public const MAX_CHOICES = 100;

    public const TEXT_MAX = 1000;

    public const TEXTAREA_MAX = 10000;

    public const MAX_FILES = 10;

    private const NAME = '/^[a-z][a-z0-9_]{0,47}$/';

    /**
     * Checks a template and returns it normalized (settings a type does
     * not take removed), with errors keyed "fields.3.label".
     *
     * @return array{fields: list<array<string, mixed>>, errors: array<string, string>}
     */
    public static function check(mixed $fields, bool $forPublishing = false): array
    {
        $errors = [];
        $out = [];
        $names = [];

        if (! is_array($fields)) {
            return ['fields' => [], 'errors' => ['fields' => 'The fields are not a list.']];
        }
        if (count($fields) > self::MAX_FIELDS) {
            $errors['fields'] = 'A log sheet has at most '.self::MAX_FIELDS.' fields.';
        }
        if ($forPublishing && $fields === []) {
            $errors['fields'] = 'Add at least one field before publishing.';
        }

        foreach (array_values($fields) as $i => $field) {
            $at = "fields.{$i}";
            if (! is_array($field)) {
                $errors[$at] = 'This field is not valid.';

                continue;
            }

            $type = is_string($field['type'] ?? null) ? $field['type'] : '';
            $label = is_string($field['label'] ?? null) ? trim($field['label']) : '';
            $name = is_string($field['name'] ?? null) ? trim($field['name']) : '';
            if ($name === '' && $label !== '') {
                $name = self::nameFor($label);
            }

            if (! array_key_exists($type, self::TYPES)) {
                $errors["{$at}.type"] = 'Choose a field type.';

                continue;
            }
            if ($label === '' || mb_strlen($label) > 120) {
                $errors["{$at}.label"] = 'A label of up to 120 characters is required.';
            }
            if (preg_match(self::NAME, $name) !== 1) {
                $errors["{$at}.name"] = 'Use lowercase letters, digits and underscores, starting with a letter.';
            } elseif (isset($names[$name])) {
                $errors["{$at}.name"] = "Another field is already named \"{$name}\".";
            }
            $names[$name] = true;

            $clean = ['name' => $name, 'label' => $label, 'type' => $type, 'required' => ($field['required'] ?? false) === true];

            $help = is_string($field['help'] ?? null) ? trim($field['help']) : '';
            if (mb_strlen($help) > 500) {
                $errors["{$at}.help"] = 'Help text is at most 500 characters.';
            }
            if ($help !== '') {
                $clean['help'] = $help;
            }

            $settings = self::SETTINGS[$type];

            if (in_array('placeholder', $settings, true) && is_string($field['placeholder'] ?? null) && trim($field['placeholder']) !== '') {
                $clean['placeholder'] = mb_substr(trim($field['placeholder']), 0, 120);
            }

            if (in_array('max_length', $settings, true) && self::filled($field['max_length'] ?? null)) {
                $limit = $type === 'text' ? self::TEXT_MAX : self::TEXTAREA_MAX;
                $max = filter_var($field['max_length'], FILTER_VALIDATE_INT);
                if ($max === false || $max < 1 || $max > $limit) {
                    $errors["{$at}.max_length"] = "Between 1 and {$limit} characters.";
                } else {
                    $clean['max_length'] = $max;
                }
            }

            if (in_array('min', $settings, true)) {
                foreach (['min', 'max'] as $bound) {
                    if (! self::filled($field[$bound] ?? null)) {
                        continue;
                    }
                    $number = self::number($type, $field[$bound]);
                    if ($number === null) {
                        $errors["{$at}.{$bound}"] = $type === 'integer' ? 'A whole number.' : 'A number.';
                    } else {
                        $clean[$bound] = $number;
                    }
                }
                if (isset($clean['min'], $clean['max']) && (float) $clean['min'] > (float) $clean['max']) {
                    $errors["{$at}.max"] = 'The maximum is below the minimum.';
                }
            }

            if (in_array('expected_min', $settings, true)) {
                foreach (['expected_min', 'expected_max'] as $bound) {
                    if (! self::filled($field[$bound] ?? null)) {
                        continue;
                    }
                    $number = self::number('decimal', $field[$bound]);
                    if ($number === null) {
                        $errors["{$at}.{$bound}"] = 'A number.';
                    } else {
                        $clean[$bound] = $number;
                    }
                }
                if (isset($clean['expected_min'], $clean['expected_max']) && (float) $clean['expected_min'] > (float) $clean['expected_max']) {
                    $errors["{$at}.expected_max"] = 'The top of the normal range is below its bottom.';
                }
            }

            if (in_array('unit', $settings, true) && is_string($field['unit'] ?? null) && trim($field['unit']) !== '') {
                $clean['unit'] = mb_substr(trim($field['unit']), 0, 20);
            }
            // ADR-0031: whether readings add up (litres, km) or not (a temperature, a tank level).
            if (in_array('summable', $settings, true) && is_bool($field['summable'] ?? null)) {
                $clean['summable'] = $field['summable'];
            }

            if (in_array('choices', $settings, true)) {
                $choices = [];
                $values = [];
                foreach (is_array($field['choices'] ?? null) ? array_values($field['choices']) : [] as $c => $choice) {
                    $choiceLabel = is_array($choice) && is_string($choice['label'] ?? null) ? trim($choice['label']) : '';
                    $value = is_array($choice) && is_string($choice['value'] ?? null) && trim($choice['value']) !== '' ? trim($choice['value']) : self::nameFor($choiceLabel);
                    if ($choiceLabel === '') {
                        $errors["{$at}.choices.{$c}"] = 'Each choice needs a label.';

                        continue;
                    }
                    if (isset($values[$value])) {
                        $errors["{$at}.choices.{$c}"] = 'This choice repeats another.';

                        continue;
                    }
                    $values[$value] = true;
                    $choices[] = ['value' => mb_substr($value, 0, 64), 'label' => mb_substr($choiceLabel, 0, 120)];
                }
                if ($choices === []) {
                    $errors["{$at}.choices"] = 'Add at least one choice.';
                } elseif (count($choices) > self::MAX_CHOICES) {
                    $errors["{$at}.choices"] = 'At most '.self::MAX_CHOICES.' choices.';
                }
                $clean['choices'] = $choices;
            }

            if (in_array('raise_exception', $settings, true) && ($field['raise_exception'] ?? false) === true) {
                if (! isset($clean['expected_min']) && ! isset($clean['expected_max'])) {
                    $errors["{$at}.raise_exception"] = 'Set a normal range for readings outside it to open an exception.';
                } else {
                    $clean['raise_exception'] = true;
                }
            }

            if (in_array('exception_values', $settings, true) && is_array($field['exception_values'] ?? null) && $field['exception_values'] !== []) {
                $allowed = $type === 'yes_no' ? ['yes', 'no'] : array_column($clean['choices'] ?? [], 'value');
                $listed = array_values(array_unique(array_map('strval', array_filter($field['exception_values'], 'is_scalar'))));
                if (array_diff($listed, $allowed) !== []) {
                    $errors["{$at}.exception_values"] = 'Choose answers from this field.';
                } else {
                    $clean['exception_values'] = $listed;
                }
            }

            if ((isset($clean['raise_exception']) || isset($clean['exception_values'])) && in_array($field['exception_severity'] ?? null, self::SEVERITIES, true)) {
                $clean['exception_severity'] = $field['exception_severity'];
            }

            if (in_array('resource_type', $settings, true) && is_string($field['resource_type'] ?? null) && trim($field['resource_type']) !== '') {
                $clean['resource_type'] = mb_substr(trim($field['resource_type']), 0, 48);
            }

            if (is_array($field['show_if'] ?? null) && self::filled($field['show_if']['field'] ?? null)) {
                [$condition, $error] = self::condition($field['show_if'], $out);
                if ($error !== null) {
                    $errors["{$at}.show_if"] = $error;
                } else {
                    $clean['show_if'] = $condition;
                }
            }

            // A default must itself be a valid answer.
            if (in_array('default', $settings, true) && self::filled($field['default'] ?? null)) {
                [$value, $error] = self::value($clean, $field['default']);
                if ($error !== null) {
                    $errors["{$at}.default"] = 'The default value is not valid: '.$error;
                } else {
                    $clean['default'] = $value;
                }
            }

            $out[] = $clean;
        }

        return ['fields' => $out, 'errors' => $errors];
    }

    /**
     * Cleans submitted values against a template version. Unknown names
     * are dropped. File fields are never taken from the request: their
     * value is the entry's own attachments ($files: field => ids).
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $files
     * @param  callable(string): bool  $isMember  whether a user id is a member of the organization
     * @param  (callable(string, string|null, string): bool)|null  $isResource  whether a resource id (of an optional type) may be chosen in the named field
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function clean(array $fields, array $input, array $files, callable $isMember, bool $requireAll, ?callable $isResource = null): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            // Hidden by its condition: not required, and its value is cleared.
            if (! self::visible($field, $values)) {
                continue;
            }
            $raw = $field['type'] === 'file' ? ($files[$name] ?? []) : ($input[$name] ?? null);

            if (! self::filled($raw)) {
                if ($requireAll && ($field['required'] ?? false) === true) {
                    $errors[$name] = $field['type'] === 'file' ? 'Attach at least one file.' : 'This field is required.';
                }

                continue;
            }

            [$value, $error] = self::value($field, $raw);
            if ($error === null && $field['type'] === 'user' && ! $isMember((string) $value)) {
                $error = 'Choose a member of this organization.';
            }
            if ($error === null && $field['type'] === 'resource' && ($isResource === null || ! $isResource((string) $value, $field['resource_type'] ?? null, $name))) {
                $error = 'Choose an active resource of this workspace that this log sheet records.';
            }

            if ($error !== null) {
                $errors[$name] = $error;
            } else {
                $values[$name] = $value;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The readings outside their field's normal range (expected_min /
     * expected_max), in template order. These are recorded, not refused;
     * reviewers and reports see them flagged.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return list<array{name: string, label: string, value: int|float, unit: string|null, direction: 'low'|'high', range: string}>
     */
    public static function deviations(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! in_array($field['type'] ?? null, self::NUMERIC, true)) {
                continue;
            }
            $value = $values[$field['name']] ?? null;
            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                continue;
            }
            $number = (float) $value;
            $low = isset($field['expected_min']) ? (float) $field['expected_min'] : null;
            $high = isset($field['expected_max']) ? (float) $field['expected_max'] : null;
            $direction = $low !== null && $number < $low ? 'low' : ($high !== null && $number > $high ? 'high' : null);
            if ($direction === null) {
                continue;
            }
            $out[] = [
                'name' => $field['name'],
                'label' => $field['label'] ?? $field['name'],
                'value' => $value + 0,
                'unit' => $field['unit'] ?? null,
                'direction' => $direction,
                'range' => self::rangeText($field),
            ];
        }

        return $out;
    }

    /**
     * "2 to 8 °C", "at least 2 °C" or "at most 8 °C": a field's normal range.
     *
     * @param  array<string, mixed>  $field
     */
    public static function rangeText(array $field): string
    {
        $unit = isset($field['unit']) ? ' '.$field['unit'] : '';
        $low = $field['expected_min'] ?? null;
        $high = $field['expected_max'] ?? null;

        return match (true) {
            $low !== null && $high !== null => "{$low} to {$high}{$unit}",
            $low !== null => "at least {$low}{$unit}",
            $high !== null => "at most {$high}{$unit}",
            default => '',
        };
    }

    /**
     * One value checked against its field: [clean value, null] or [null, error].
     *
     * @param  array<string, mixed>  $field
     * @return array{0: mixed, 1: string|null}
     */
    public static function value(array $field, mixed $raw): array
    {
        $type = $field['type'];

        switch ($type) {
            case 'text':
            case 'textarea':
                if (! is_scalar($raw)) {
                    return [null, 'Enter text.'];
                }
                $text = trim(str_replace("\0", '', (string) $raw));
                $max = (int) ($field['max_length'] ?? ($type === 'text' ? self::TEXT_MAX : self::TEXTAREA_MAX));
                if ($type === 'text') {
                    $text = (string) preg_replace('/\s+/u', ' ', $text);
                }

                return mb_strlen($text) > $max ? [null, "At most {$max} characters."] : [$text, null];

            case 'integer':
            case 'decimal':
                $number = self::number($type, $raw);
                if ($number === null) {
                    return [null, $type === 'integer' ? 'Enter a whole number.' : 'Enter a number.'];
                }
                if (isset($field['min']) && (float) $number < (float) $field['min']) {
                    return [null, "At least {$field['min']}."];
                }
                if (isset($field['max']) && (float) $number > (float) $field['max']) {
                    return [null, "At most {$field['max']}."];
                }

                return [$number, null];

            case 'date':
                return is_string($raw) && self::validDate($raw) ? [$raw, null] : [null, 'Enter a date (YYYY-MM-DD).'];

            case 'time':
                return is_string($raw) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) === 1 ? [$raw, null] : [null, 'Enter a time (HH:MM).'];

            case 'datetime':
                if (is_string($raw) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(:\d{2})?$/', $raw, $m) === 1 && self::validDate($m[1])) {
                    return ["{$m[1]}T{$m[2]}:{$m[3]}", null];
                }

                return [null, 'Enter a date and time.'];

            case 'select_one':
                $allowed = array_column($field['choices'] ?? [], 'value');

                return is_string($raw) && in_array($raw, $allowed, true) ? [$raw, null] : [null, 'Choose one of the options.'];

            case 'select_multiple':
                $allowed = array_column($field['choices'] ?? [], 'value');
                if (! is_array($raw)) {
                    return [null, 'Choose from the options.'];
                }
                $picked = array_values(array_unique(array_filter($raw, is_string(...))));
                if (count($picked) !== count($raw) || array_diff($picked, $allowed) !== []) {
                    return [null, 'Choose from the options.'];
                }

                // Kept in the template's order.
                return [array_values(array_intersect($allowed, $picked)), null];

            case 'yes_no':
                return match (true) {
                    $raw === true, $raw === 'yes', $raw === '1', $raw === 1, $raw === 'true' => [true, null],
                    $raw === false, $raw === 'no', $raw === '0', $raw === 0, $raw === 'false' => [false, null],
                    default => [null, 'Answer yes or no.'],
                };

            case 'user':
                return is_string($raw) && Str::isUuid($raw) ? [$raw, null] : [null, 'Choose a person.'];

            case 'resource':
                return is_string($raw) && Str::isUuid($raw) ? [$raw, null] : [null, 'Choose a resource.'];

            case 'file':
                if (! is_array($raw) || count($raw) > self::MAX_FILES) {
                    return [null, 'At most '.self::MAX_FILES.' files.'];
                }

                return [array_values(array_filter($raw, is_string(...))), null];
        }

        return [null, 'Unknown field type.'];
    }

    /**
     * A value as people read it.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $people  user id => name
     * @param  array<string, string>  $fileNames  attachment id => name
     */
    public static function text(array $field, mixed $value, array $people = [], array $fileNames = []): ?string
    {
        if (! self::filled($value)) {
            return null;
        }

        $labels = array_column($field['choices'] ?? [], 'label', 'value');

        return match ($field['type']) {
            'select_one' => $labels[$value] ?? (string) $value,
            'select_multiple' => implode(', ', array_map(fn ($v) => $labels[$v] ?? (string) $v, (array) $value)),
            'yes_no' => $value === true ? 'Yes' : 'No',
            'user' => $people[$value] ?? 'Unknown person',
            'resource' => $people[$value] ?? 'Unknown resource',
            'file' => implode(', ', array_map(fn ($id) => $fileNames[$id] ?? 'File', (array) $value)),
            'datetime' => str_replace('T', ' ', (string) $value),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }

    /**
     * Lowercased text that keyword search runs on.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $people
     */
    public static function searchText(?string $reference, array $fields, array $values, array $people): string
    {
        $parts = [$reference ?? ''];
        foreach ($fields as $field) {
            if ($field['type'] === 'file') {
                continue;
            }
            $parts[] = self::text($field, $values[$field['name']] ?? null, $people) ?? '';
        }

        return mb_substr(mb_strtolower(trim(implode(' ', array_filter($parts)))), 0, 20000);
    }

    /**
     * The fields' default values, for a new entry.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function defaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists('default', $field)) {
                $out[$field['name']] = $field['default'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function byName(array $fields): array
    {
        return array_column($fields, null, 'name');
    }

    public static function nameFor(string $label): string
    {
        $name = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->substr(0, 48)->toString();

        return preg_match('/^[a-z]/', $name) === 1 ? $name : 'f_'.mb_substr($name, 0, 46);
    }

    /**
     * Whether a field shows, given the values answered so far: always
     * without show_if; otherwise when the named field's answer is listed
     * (any listed choice, for multiple choice).
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>|null  $fields  the template; when given, a condition on a hidden field is itself false
     */
    public static function visible(array $field, array $values, ?array $fields = null): bool
    {
        $condition = $field['show_if'] ?? null;
        if (! is_array($condition) || ! is_string($condition['field'] ?? null)) {
            return true;
        }
        if ($fields !== null) {
            $parent = self::byName($fields)[$condition['field']] ?? null;
            if ($parent !== null && ! self::visible($parent, $values, $fields)) {
                return false;
            }
        }
        $answer = $values[$condition['field']] ?? null;
        $listed = array_map('strval', (array) ($condition['in'] ?? []));

        return match (true) {
            is_bool($answer) => in_array($answer ? 'yes' : 'no', $listed, true),
            is_array($answer) => array_intersect(array_map('strval', $answer), $listed) !== [],
            is_scalar($answer) => in_array((string) $answer, $listed, true),
            default => false,
        };
    }

    /**
     * A show_if checked against the fields before it: [{field, in}, null] or [null, error].
     *
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $earlier
     * @return array{0: array{field: string, in: list<string>}|null, 1: string|null}
     */
    private static function condition(array $raw, array $earlier): array
    {
        $parent = self::byName($earlier)[(string) $raw['field']] ?? null;
        if ($parent === null) {
            return [null, 'Show this field depending on a field above it.'];
        }
        if (! in_array($parent['type'], self::CONDITIONAL, true)) {
            return [null, 'Only a yes/no or choice field can decide whether another shows.'];
        }
        $listed = isset($raw['in']) && is_array($raw['in']) ? $raw['in'] : (isset($raw['equals']) ? [$raw['equals']] : []);
        $listed = array_values(array_unique(array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v, array_filter($listed, fn ($v) => is_scalar($v)))));
        $allowed = $parent['type'] === 'yes_no' ? ['yes', 'no'] : array_column($parent['choices'] ?? [], 'value');
        if ($listed === [] || array_diff($listed, $allowed) !== []) {
            return [null, 'Choose which answers of "'.$parent['label'].'" show this field.'];
        }

        return [['field' => (string) $parent['name'], 'in' => $listed], null];
    }

    public static function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    /** An integer as int; a decimal as a normalized string (exact). */
    private static function number(string $type, mixed $raw): int|string|null
    {
        if (is_int($raw)) {
            return $type === 'integer' ? $raw : (string) $raw;
        }
        if (is_float($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        if ($type === 'integer') {
            return preg_match('/^-?\d{1,15}$/', $raw) === 1 ? (int) $raw : null;
        }
        if (preg_match('/^(-?)(\d{1,15})(?:\.(\d{1,6}))?$/', $raw, $m) !== 1) {
            return null;
        }
        $fraction = rtrim($m[3] ?? '', '0');
        $whole = ltrim($m[2], '0') ?: '0';
        $sign = ($whole === '0' && $fraction === '') ? '' : $m[1];

        return $sign.$whole.($fraction !== '' ? '.'.$fraction : '');
    }

    private static function validDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
