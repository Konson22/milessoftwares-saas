<?php

namespace App\Logbook;

/**
 * Ready-made log sheets an organization can copy into a logbook and then
 * change. They are only configurations of the generic engine: nothing
 * about generators, vehicles or maintenance exists anywhere else.
 */
final class StarterTemplates
{
    /**
     * @return array<string, array{name: string, code: string, description: string, review_required: bool, fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $status = fn (string $name, string $label, array $choices, bool $required = true) => [
            'name' => $name, 'label' => $label, 'type' => 'select_one', 'required' => $required,
            'choices' => array_map(fn ($c) => ['value' => Fields::nameFor($c), 'label' => $c], $choices),
        ];

        return [
            'daily_activity' => [
                'name' => 'Daily Activity Log',
                'code' => 'ACT',
                'description' => 'What was done each day, where and by whom.',
                'review_required' => true,
                'fields' => [
                    ['name' => 'activity_date', 'label' => 'Activity date', 'type' => 'date', 'required' => true],
                    ['name' => 'description', 'label' => 'Activity description', 'type' => 'textarea', 'required' => true],
                    ['name' => 'location', 'label' => 'Location', 'type' => 'text', 'required' => false],
                    ['name' => 'responsible_person', 'label' => 'Responsible person', 'type' => 'user', 'required' => true],
                    ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => false],
                    ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => false],
                    $status('completion_status', 'Completion status', ['Completed', 'In progress', 'Not started', 'Cancelled']),
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false],
                    ['name' => 'attachment', 'label' => 'Attachment', 'type' => 'file', 'required' => false],
                ],
            ],
            'generator_operation' => [
                'name' => 'Generator Operation Log',
                'code' => 'GEN',
                'description' => 'Each generator run: hours, fuel and condition.',
                'review_required' => true,
                'fields' => [
                    ['name' => 'generator', 'label' => 'Generator identifier', 'type' => 'text', 'required' => true, 'max_length' => 60],
                    ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => true],
                    ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => false],
                    ['name' => 'running_hours', 'label' => 'Running hours', 'type' => 'decimal', 'required' => true, 'min' => '0', 'max' => '24'],
                    ['name' => 'fuel_litres', 'label' => 'Fuel consumed (litres)', 'type' => 'decimal', 'required' => true, 'min' => '0'],
                    $status('condition', 'Equipment condition', ['Good', 'Needs attention', 'Faulty']),
                    ['name' => 'operator', 'label' => 'Operator', 'type' => 'user', 'required' => true],
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false],
                ],
            ],
            'vehicle_movement' => [
                'name' => 'Vehicle Movement Log',
                'code' => 'VEH',
                'description' => 'Every trip: driver, route, mileage and purpose.',
                'review_required' => true,
                'fields' => [
                    ['name' => 'vehicle', 'label' => 'Vehicle identifier', 'type' => 'text', 'required' => true, 'max_length' => 40],
                    ['name' => 'driver', 'label' => 'Driver', 'type' => 'user', 'required' => true],
                    ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['name' => 'departure_time', 'label' => 'Departure time', 'type' => 'time', 'required' => true],
                    ['name' => 'return_time', 'label' => 'Return time', 'type' => 'time', 'required' => false],
                    ['name' => 'origin', 'label' => 'Origin', 'type' => 'text', 'required' => true],
                    ['name' => 'destination', 'label' => 'Destination', 'type' => 'text', 'required' => true],
                    ['name' => 'start_mileage', 'label' => 'Starting mileage (km)', 'type' => 'integer', 'required' => true, 'min' => 0],
                    ['name' => 'end_mileage', 'label' => 'Ending mileage (km)', 'type' => 'integer', 'required' => false, 'min' => 0],
                    ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'textarea', 'required' => true],
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false],
                ],
            ],
            'it_maintenance' => [
                'name' => 'IT Maintenance Log',
                'code' => 'ITM',
                'description' => 'Repairs and maintenance on IT equipment.',
                'review_required' => false,
                'fields' => [
                    ['name' => 'equipment', 'label' => 'Equipment identifier', 'type' => 'text', 'required' => true, 'max_length' => 60],
                    ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['name' => 'technician', 'label' => 'Technician', 'type' => 'user', 'required' => true],
                    $status('maintenance_type', 'Maintenance type', ['Preventive', 'Corrective', 'Upgrade', 'Inspection']),
                    ['name' => 'problem', 'label' => 'Problem description', 'type' => 'textarea', 'required' => true],
                    ['name' => 'action_taken', 'label' => 'Action taken', 'type' => 'textarea', 'required' => true],
                    $status('outcome', 'Outcome', ['Resolved', 'Partially resolved', 'Not resolved']),
                    ['name' => 'follow_up', 'label' => 'Follow-up required', 'type' => 'yes_no', 'required' => true, 'default' => false],
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false],
                    ['name' => 'attachments', 'label' => 'Attachments', 'type' => 'file', 'required' => false],
                ],
            ],
        ];
    }
}
