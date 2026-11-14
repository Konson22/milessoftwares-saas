<?php

namespace App\Collect;

use Carbon\CarbonImmutable;

/**
 * The logic and validation engine (ADR-0012 §4). Rules are data, never
 * code: nothing in a definition is ever executed.
 *
 * resources/js/collect/rules.ts is the same engine for the builder's
 * preview and the field app. Both run every case in
 * contracts/collect/rules-cases.json; a change here is a change there.
 *
 * The semantics, in full:
 *
 * - Items are evaluated in document order. An item is visible when its
 *   group is visible and its `relevant` condition (if any) holds.
 * - A condition is {all: [...]} (true when empty), {any: [...]} (false
 *   when empty), {not: c}, or a leaf {q, op, value}. A leaf looks at the
 *   answer to question q *as cleaned so far*: hidden, unanswered or
 *   invalid answers count as unanswered.
 * - `answered` / `not_answered` test presence. Every other operator is
 *   false on an unanswered question (including `ne`).
 * - `eq` / `ne` compare numbers numerically and strings exactly; a number
 *   never equals a string. `gt` `gte` `lt` `lte` compare two numbers, or
 *   two strings by code unit (ISO dates and times sort correctly).
 *   `includes`: the answer (a list) contains the value. `in`: the value
 *   (a list) contains the answer.
 * - Unanswered means: missing, null, "" or an empty list.
 * - A visible question is required when `required` is true or its
 *   `required_if` condition holds. A required unanswered question is an
 *   error "required". An answered one is checked against its type and
 *   constraints, and only a valid answer is kept.
 * - The cleaned answers are the valid answers of visible questions: hidden
 *   questions' answers and unknown names are dropped, never validated.
 * - Text lengths count code points. Date bounds are "YYYY-MM-DD",
 *   "today", "today-Nd" or "today+Nd", resolved against the day the form
 *   was completed (never the day it arrived).
 *
 * Error codes: required, type, choice, min, max, min_length, max_length,
 * pattern, min_choices, max_choices.
 */
final class Rules
{
    public const PATTERNS = [
        'digits' => '/^[0-9]+$/',
        'phone' => '/^\+?[0-9][0-9 ]{5,19}$/',
    ];

    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array{visible: list<string>, required: list<string>, answers: array<string, mixed>, errors: array<string, string>}
     */
    public static function evaluate(array $definition, array $answers, string $today): array
    {
        $state = ['visible' => [], 'required' => [], 'answers' => [], 'errors' => []];
        self::walk($definition['items'] ?? [], true, $definition, $answers, $today, $state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @param  array{visible: list<string>, required: list<string>, answers: array<string, mixed>, errors: array<string, string>}  $state
     */
    private static function walk(mixed $items, bool $shown, array $definition, array $answers, string $today, array &$state): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                continue;
            }
            $name = $item['name'];
            $type = $item['type'] ?? null;
            $visible = $shown && (! isset($item['relevant']) || self::test($item['relevant'], $state['answers']));

            if ($visible) {
                $state['visible'][] = $name;
            }

            if ($type === 'group') {
                self::walk($item['items'] ?? [], $visible, $definition, $answers, $today, $state);

                continue;
            }

            if (! $visible || ! in_array($type, Definition::ANSWERABLE, true)) {
                continue;
            }

            $required = ($item['required'] ?? false) === true
                || (isset($item['required_if']) && self::test($item['required_if'], $state['answers']));
            if ($required) {
                $state['required'][] = $name;
            }

            $answer = $answers[$name] ?? null;
            if (self::isEmpty($answer)) {
                if ($required) {
                    $state['errors'][$name] = 'required';
                }

                continue;
            }

            $error = self::check($item, $answer, $definition, $today);
            if ($error !== null) {
                $state['errors'][$name] = $error;
            } else {
                $state['answers'][$name] = $answer;
            }
        }
    }

    /**
     * Whether a condition holds against the answers cleaned so far.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function test(mixed $condition, array $answers): bool
    {
        if (! is_array($condition)) {
            return false;
        }

        if (array_key_exists('all', $condition)) {
            foreach (is_array($condition['all']) ? $condition['all'] : [] as $part) {
                if (! self::test($part, $answers)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach (is_array($condition['any']) ? $condition['any'] : [] as $part) {
                if (self::test($part, $answers)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $condition)) {
            return ! self::test($condition['not'], $answers);
        }

        $op = $condition['op'] ?? null;
        $answered = is_string($condition['q'] ?? null) && array_key_exists($condition['q'], $answers);
        $answer = $answered ? $answers[$condition['q']] : null;
        $value = $condition['value'] ?? null;

        if ($op === 'answered') {
            return $answered;
        }
        if ($op === 'not_answered') {
            return ! $answered;
        }
        if (! $answered) {
            return false;
        }

        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            $order = self::compare($answer, $value);

            return $order !== null && match ($op) {
                'gt' => $order > 0,
                'gte' => $order >= 0,
                'lt' => $order < 0,
                'lte' => $order <= 0,
            };
        }

        return match ($op) {
            'eq' => self::equals($answer, $value),
            'ne' => ! self::equals($answer, $value),
            'includes' => is_array($answer) && array_is_list($answer) && self::contains($answer, $value),
            'in' => is_array($value) && array_is_list($value) && self::contains($value, $answer),
            default => false,
        };
    }

    public static function isEmpty(mixed $answer): bool
    {
        return $answer === null || $answer === '' || $answer === [];
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return (float) $a === (float) $b;
        }

        return is_string($a) && is_string($b) && $a === $b;
    }

    /**
     * -1, 0 or 1; null when the two cannot be compared.
     */
    private static function compare(mixed $a, mixed $b): ?int
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return (float) $a <=> (float) $b;
        }

        if (is_string($a) && is_string($b)) {
            // Code-unit order, as JavaScript compares strings: ISO dates and times sort correctly.
            return strcmp(self::utf16($a), self::utf16($b)) <=> 0;
        }

        return null;
    }

    private static function utf16(string $value): string
    {
        return (string) mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    /**
     * @param  list<mixed>  $list
     */
    private static function contains(array $list, mixed $value): bool
    {
        foreach ($list as $entry) {
            if (self::equals($entry, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The error code for an answer, or null when it is valid.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $definition
     */
    private static function check(array $question, mixed $answer, array $definition, string $today): ?string
    {
        $constraint = is_array($question['constraint'] ?? null) ? $question['constraint'] : [];

        switch ($question['type']) {
            case 'text':
            case 'textarea':
                if (! is_string($answer)) {
                    return 'type';
                }
                $length = mb_strlen($answer, 'UTF-8');
                $max = $constraint['max_length'] ?? ($question['type'] === 'text' ? Definition::TEXT_MAX : Definition::TEXTAREA_MAX);
                if (isset($constraint['min_length']) && $length < $constraint['min_length']) {
                    return 'min_length';
                }
                if ($length > $max) {
                    return 'max_length';
                }
                if (isset($constraint['pattern']) && preg_match(self::PATTERNS[$constraint['pattern']] ?? '/(?!)/', $answer) !== 1) {
                    return 'pattern';
                }

                return null;

            case 'integer':
            case 'decimal':
                if (! self::isNumber($answer) || ($question['type'] === 'integer' && floor((float) $answer) !== (float) $answer)) {
                    return 'type';
                }

                return self::range($answer, $constraint['min'] ?? null, $constraint['max'] ?? null);

            case 'select_one':
                if (! is_string($answer)) {
                    return 'type';
                }

                return array_key_exists($answer, Definition::choices($definition, $question)) ? null : 'choice';

            case 'yes_no':
                return in_array($answer, ['yes', 'no'], true) ? null : 'type';

            case 'select_multiple':
                if (! is_array($answer) || ! array_is_list($answer)) {
                    return 'type';
                }
                foreach ($answer as $choice) {
                    if (! is_string($choice)) {
                        return 'type';
                    }
                }
                if (count(array_unique($answer)) !== count($answer)) {
                    return 'type';
                }
                $choices = Definition::choices($definition, $question);
                foreach ($answer as $choice) {
                    if (! array_key_exists($choice, $choices)) {
                        return 'choice';
                    }
                }
                if (isset($constraint['min_choices']) && count($answer) < $constraint['min_choices']) {
                    return 'min_choices';
                }
                if (isset($constraint['max_choices']) && count($answer) > $constraint['max_choices']) {
                    return 'max_choices';
                }

                return null;

            case 'date':
                if (! is_string($answer) || ! self::isDate($answer)) {
                    return 'type';
                }

                return self::range($answer, self::bound($constraint['min'] ?? null, $today), self::bound($constraint['max'] ?? null, $today));

            case 'time':
                if (! is_string($answer) || preg_match(self::TIME, $answer) !== 1) {
                    return 'type';
                }

                return self::range($answer, $constraint['min'] ?? null, $constraint['max'] ?? null);

            case 'datetime':
                if (! is_string($answer) || strlen($answer) !== 16 || $answer[10] !== 'T'
                    || ! self::isDate(substr($answer, 0, 10)) || preg_match(self::TIME, substr($answer, 11)) !== 1) {
                    return 'type';
                }

                return self::range(substr($answer, 0, 10), self::bound($constraint['min'] ?? null, $today), self::bound($constraint['max'] ?? null, $today));

            case 'geopoint':
                if (! is_array($answer) || array_is_list($answer)
                    || ! self::isNumber($answer['lat'] ?? null) || ! self::isNumber($answer['lng'] ?? null)
                    || abs((float) $answer['lat']) > 90 || abs((float) $answer['lng']) > 180
                    || (isset($answer['accuracy']) && (! self::isNumber($answer['accuracy']) || $answer['accuracy'] < 0))) {
                    return 'type';
                }

                return null;

            case 'photo':
                return is_string($answer) && preg_match('/^[0-9a-f]{64}$/', $answer) === 1 ? null : 'type';
        }

        return 'type';
    }

    private static function range(mixed $answer, mixed $min, mixed $max): ?string
    {
        if ($min !== null && (self::compare($answer, $min) ?? 0) < 0) {
            return 'min';
        }
        if ($max !== null && (self::compare($answer, $max) ?? 0) > 0) {
            return 'max';
        }

        return null;
    }

    public static function isDate(string $value): bool
    {
        return preg_match(self::DATE, $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A date bound as a date: "today", "today-30d" and "today+7d" resolved.
     */
    public static function bound(mixed $bound, string $today): ?string
    {
        if (! is_string($bound)) {
            return null;
        }
        if ($bound === 'today') {
            return $today;
        }
        if (preg_match('/^today([+-])(\d{1,4})d$/', $bound, $m) === 1) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');
            if ($day === null) {
                return null;
            }

            return ($m[1] === '+' ? $day->addDays((int) $m[2]) : $day->subDays((int) $m[2]))->toDateString();
        }

        return self::isDate($bound) ? $bound : null;
    }
}
