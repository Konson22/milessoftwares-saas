<?php

namespace App\Mel;

use App\Models\IndicatorVersion;
use Illuminate\Validation\ValidationException;

/**
 * Checks a figure (a value, or a numerator and denominator) against the
 * indicator's measure, for baselines, targets, submissions and imports.
 */
final class Figures
{
    /**
     * @param  array<string, mixed>  $input  value | numerator + denominator
     * @param  string  $prefix  for the error keys ("cells.3.")
     * @return array{value: string|null, numerator: string|null, denominator: string|null}
     */
    public static function from(IndicatorVersion $version, array $input, string $prefix = '', bool $required = true): array
    {
        $errors = [];

        if ($version->isFraction()) {
            $numerator = Numbers::parse($input['numerator'] ?? null);
            $denominator = Numbers::parse($input['denominator'] ?? null);

            foreach (['numerator' => $numerator, 'denominator' => $denominator] as $key => $parsed) {
                if ($parsed === null && (($input[$key] ?? '') !== '' || $required)) {
                    $errors[$prefix.$key] = ($input[$key] ?? '') === '' ? 'Enter the '.self::part($version, $key).'.' : 'Enter a number.';
                } elseif ($parsed !== null && Numbers::isNegative($parsed)) {
                    $errors[$prefix.$key] = 'It cannot be negative.';
                }
            }

            if ($denominator !== null && Numbers::isZero($denominator)) {
                $errors[$prefix.'denominator'] = 'The '.self::part($version, 'denominator').' cannot be zero.';
            }

            if ($version->measure === 'percentage' && $numerator !== null && $denominator !== null && Numbers::compare($numerator, $denominator) > 0) {
                $errors[$prefix.'numerator'] = 'A percentage\'s '.self::part($version, 'numerator').' cannot be more than its '.self::part($version, 'denominator').'.';
            }

            if (($numerator === null) !== ($denominator === null) && ! $required && $errors === []) {
                $errors[$prefix.($numerator === null ? 'numerator' : 'denominator')] = 'Enter both parts, or neither.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return ['value' => null, 'numerator' => $numerator, 'denominator' => $denominator];
        }

        $value = Numbers::parse($input['value'] ?? null);

        if ($value === null) {
            if (($input['value'] ?? '') !== '' || $required) {
                throw ValidationException::withMessages([$prefix.'value' => ($input['value'] ?? '') === '' ? 'Enter a value.' : 'Enter a number.']);
            }

            return ['value' => null, 'numerator' => null, 'denominator' => null];
        }

        $error = match (true) {
            $version->measure === 'yes_no' && ! in_array(Numbers::trim($value), ['0', '1'], true) => 'Enter 1 for yes or 0 for no.',
            in_array($version->measure, ['count', 'currency'], true) && Numbers::isNegative($value) => 'It cannot be negative.',
            $version->measure === 'count' && ! ctype_digit(Numbers::trim($value)) => 'A count is a whole number.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$prefix.'value' => $error]);
        }

        return ['value' => $value, 'numerator' => null, 'denominator' => null];
    }

    private static function part(IndicatorVersion $version, string $key): string
    {
        $label = $key === 'numerator' ? $version->numerator_label : $version->denominator_label;

        return $label !== null && $label !== '' ? mb_strtolower($label) : $key;
    }
}
