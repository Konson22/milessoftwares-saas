<?php

namespace App\Collaboration;

use App\Enums\CostCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What each kind of project request carries (ADR-0015 §5), validated per
 * type. Amounts are what is asked, never what is approved or paid.
 */
final class RequestDetails
{
    /**
     * @param  array<array-key, mixed>  $input
     * @return array{details: array<string, mixed>, amountMinor: int|null, currency: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(string $type, array $input, string $projectCurrency): array
    {
        $categories = array_map(fn (CostCategory $category) => $category->value, CostCategory::cases());

        $rules = match ($type) {
            'funding' => [
                'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
                'currency' => ['required', 'string', 'size:3'],
                'agreement_reference' => ['nullable', 'string', 'max:120'],
                'categories' => ['nullable', 'array', 'max:20'],
                'categories.*' => ['string', Rule::in($categories)],
                'needed_by' => ['nullable', 'date'],
            ],
            'budget_revision' => [
                'changes' => ['required', 'array', 'min:1', 'max:30'],
                'changes.*.category' => ['required', 'string', Rule::in($categories)],
                'changes.*.current_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
                'changes.*.proposed_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
                'changes.*.reason' => ['nullable', 'string', 'max:1000'],
            ],
            'activity_change' => [
                'activity' => ['required', 'string', 'max:300'],
                'change' => ['required', 'string', 'max:5000'],
            ],
            'timeline_extension' => [
                'current_end' => ['nullable', 'date'],
                'proposed_end' => ['required', 'date', 'after:today'],
            ],
            'information' => [
                'asked' => ['required', 'string', 'max:5000'],
                'needed_by' => ['nullable', 'date'],
            ],
            default => throw ValidationException::withMessages(['type' => 'Choose a kind of request.']),
        };

        $valid = Validator::make($input, $rules)->validate();

        return match ($type) {
            'funding' => [
                'details' => [
                    'agreementReference' => self::text($valid['agreement_reference'] ?? null),
                    'categories' => array_values(array_unique($valid['categories'] ?? [])),
                    'neededBy' => $valid['needed_by'] ?? null,
                ],
                'amountMinor' => (int) $valid['amount_minor'],
                'currency' => strtoupper((string) $valid['currency']),
            ],
            'budget_revision' => [
                'details' => ['changes' => array_values(array_map(fn (array $change) => [
                    'category' => $change['category'],
                    'currentMinor' => (int) $change['current_minor'],
                    'proposedMinor' => (int) $change['proposed_minor'],
                    'reason' => self::text($change['reason'] ?? null),
                ], $valid['changes']))],
                'amountMinor' => null,
                'currency' => $projectCurrency,
            ],
            'activity_change' => ['details' => ['activity' => trim($valid['activity']), 'change' => trim($valid['change'])], 'amountMinor' => null, 'currency' => null],
            'timeline_extension' => ['details' => ['currentEnd' => $valid['current_end'] ?? null, 'proposedEnd' => $valid['proposed_end']], 'amountMinor' => null, 'currency' => null],
            'information' => ['details' => ['asked' => trim($valid['asked']), 'neededBy' => $valid['needed_by'] ?? null], 'amountMinor' => null, 'currency' => null],
        };
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
