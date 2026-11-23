<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Procurement\Approvals;
use App\Procurement\Documents;
use App\Procurement\Money;
use App\Procurement\Sourcing;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    protected function user(): User
    {
        $user = request()->user();
        assert($user instanceof User);

        return $user;
    }

    /** Minor units → "1250.50" in the currency. */
    protected static function major(?int $minor, string $currency): ?string
    {
        return $minor === null ? null : Money::major($minor, $currency);
    }

    protected static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    protected static function time(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected static function methodOptions(bool $sourcingOnly = false): array
    {
        $options = [];
        foreach (Sourcing::LABELS as $value => $label) {
            if ($sourcingOnly && in_array($value, ['direct', 'framework'], true)) {
                continue;
            }
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * The approval block every document page shows: history, the step now
     * waiting and whether this member may decide it.
     *
     * @return array{history: list<array<string, mixed>>, waiting: string|null, canDecide: bool}
     */
    protected function approval(string $type, Model $document): array
    {
        $current = Approvals::current($type, $document);

        return [
            'history' => Approvals::history($type, $document),
            'waiting' => $current === null ? null : 'Step '.$current->step.': '.$current->getAttribute('approver_label'),
            'canDecide' => $current !== null && Approvals::refusal($current, $this->user()) === null,
            'refusal' => $current === null ? null : Approvals::refusal($current, $this->user()),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, canAdd: bool, type: string, id: string}
     */
    protected function documents(string $type, Model $subject): array
    {
        [, $mayAdd] = Documents::subject($type, (string) $subject->getKey());

        return ['items' => Documents::for($type, $subject), 'canAdd' => $mayAdd, 'type' => $type, 'id' => (string) $subject->getKey()];
    }
}
