<?php

namespace App\Collaboration;

/**
 * What someone does on a project: for a member of its team, or someone who
 * represents a participating organization. It says who to turn to; what they
 * may do is still their permissions (and, for a participant, its
 * capabilities).
 */
final class Responsibility
{
    public const LABELS = [
        'project_manager' => 'Project manager',
        'department_representative' => 'Department representative',
        'finance' => 'Finance representative',
        'procurement' => 'Procurement officer',
        'mel' => 'M&E officer',
        'field' => 'Field officer',
        'consultant' => 'Consultant',
        'partner_representative' => 'Partner representative',
    ];

    /**
     * Known keys only, in this order.
     *
     * @param  array<array-key, mixed>|null  $given
     * @return list<string>
     */
    public static function normalize(?array $given): array
    {
        $given = array_filter($given ?? [], 'is_string');

        return array_values(array_filter(array_keys(self::LABELS), fn (string $key) => in_array($key, $given, true)));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $key) => ['value' => $key, 'label' => self::LABELS[$key]], array_keys(self::LABELS));
    }

    /**
     * @param  list<string>|null  $keys
     * @return list<string>
     */
    public static function labels(?array $keys): array
    {
        return array_map(fn (string $key) => self::LABELS[$key] ?? $key, $keys ?? []);
    }
}
