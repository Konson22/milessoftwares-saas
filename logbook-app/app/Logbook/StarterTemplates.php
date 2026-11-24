<?php

namespace App\Logbook;

/**
 * Ready-made log sheets an organization can copy into a logbook and then
 * change. They are only configurations of the generic engine: nothing
 * about generators, vehicles or maintenance exists anywhere else.
 *
 * ADR-0031: the asset a sheet is about is a Resource field (a registered
 * asset, never typed in), named as the sheet's main resource field. A
 * starter may suggest how it is recorded (mode, fuel estimate, GPS); the
 * copy is a draft, so everything stays changeable. Normal ranges are left
 * empty on purpose: there is no universal threshold, each organization
 * sets its own.
 */
final class StarterTemplates
{
    /**
     * @return array<string, array{name: string, code: string, description: string, review_required: bool, fields: list<array<string, mixed>>, recording?: array<string, mixed>, location?: array<string, mixed>, asset_types?: list<string>}>
     */
    public static function all(): array
    {
        $status = fn (string $name, string $label, array $choices, bool $required = true, array $more = []) => [
            'name' => $name, 'label' => $label, 'type' => 'select_one', 'required' => $required,
            'choices' => array_map(fn ($c) => ['value' => Fields::nameFor($c), 'label' => $c], $choices),
            ...$more,
        ];
        $asset = fn (string $name, string $label, ?string $type = null) => [
            'name' => $name, 'label' => $label, 'type' => 'resource', 'required' => true, ...($type !== null ? ['resource_type' => $type] : []),
        ];
        $text = fn (string $name, string $label, bool $required = false, string $type = 'textarea') => ['name' => $name, 'label' => $label, 'type' => $type, 'required' => $required];

        return [
            'daily_activity' => [
                'name' => 'Daily Activity Log',
                'code' => 'ACT',
                'description' => 'What was done each day, where and by whom.',
                'review_required' => true,
                'recording' => ['mode' => 'on_demand', 'shifts' => 'optional'],
                'fields' => [
                    ['name' => 'activity_date', 'label' => 'Activity date', 'type' => 'date', 'required' => true],
                    $text('description', 'Activity description', true),
                    $text('location', 'Location', false, 'text'),
                    ['name' => 'responsible_person', 'label' => 'Responsible person', 'type' => 'user', 'required' => true],
                    ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => false],
                    ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => false],
                    $status('completion_status', 'Completion status', ['Completed', 'In progress', 'Not started', 'Cancelled']),
                    $text('remarks', 'Remarks'),
                    ['name' => 'attachment', 'label' => 'Attachment', 'type' => 'file', 'required' => false],
                ],
            ],
            'generator_operation' => [
                'name' => 'Generator Operation Log',
                'code' => 'GEN',
                'description' => 'Each generator run: hour meter, fuel and condition.',
                'review_required' => true,
                'asset_types' => ['generator'],
                'recording' => ['mode' => 'event', 'shifts' => 'optional', 'subject' => 'generator', 'activity' => [
                    'resource_field' => 'generator', 'measure' => 'hour_meter', 'start_field' => 'hours_start', 'end_field' => 'hours_end',
                    'actual_field' => 'fuel_used', 'refill_field' => 'fuel_added',
                ]],
                'fields' => [
                    $asset('generator', 'Generator', 'Generator'),
                    ['name' => 'hours_start', 'label' => 'Hour meter at start', 'type' => 'decimal', 'required' => true, 'min' => '0', 'unit' => 'h'],
                    ['name' => 'hours_end', 'label' => 'Hour meter at end', 'type' => 'decimal', 'required' => true, 'min' => '0', 'unit' => 'h'],
                    ['name' => 'fuel_used', 'label' => 'Fuel used, if measured', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'L'],
                    ['name' => 'fuel_added', 'label' => 'Fuel added (refill)', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'L'],
                    ['name' => 'load', 'label' => 'Load', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => '%'],
                    $status('condition', 'Equipment condition', ['Good', 'Needs attention', 'Faulty'], true, ['exception_values' => ['faulty'], 'exception_severity' => 'high']),
                    ['name' => 'operator', 'label' => 'Operator', 'type' => 'user', 'required' => true],
                    $text('remarks', 'Remarks'),
                ],
            ],
            'vehicle_movement' => [
                'name' => 'Vehicle Movement Log',
                'code' => 'VEH',
                'description' => 'Every trip by the driver assigned to the vehicle: start, stops, odometer, fuel and purpose.',
                'review_required' => true,
                'asset_types' => ['vehicle'],
                // ADR-0032: the driver is the author, records only vehicles assigned to them, and each stop.
                'recording' => ['mode' => 'event', 'shifts' => 'off', 'subject' => 'vehicle', 'stops' => 'optional', 'assigned_only' => true, 'activity' => [
                    'resource_field' => 'vehicle', 'measure' => 'odometer', 'start_field' => 'start_mileage', 'end_field' => 'end_mileage',
                    'actual_field' => null, 'refill_field' => 'fuel_added',
                ]],
                'location' => ['mode' => 'route', 'required' => false, 'resource_required' => false],
                'fields' => [
                    $asset('vehicle', 'Vehicle', 'Vehicle'),
                    $text('origin', 'Origin', true, 'text'),
                    $text('destination', 'Destination', true, 'text'),
                    ['name' => 'start_mileage', 'label' => 'Odometer at start', 'type' => 'integer', 'required' => true, 'min' => 0, 'unit' => 'km'],
                    ['name' => 'end_mileage', 'label' => 'Odometer at end', 'type' => 'integer', 'required' => true, 'min' => 0, 'unit' => 'km'],
                    $status('fuel_start', 'Fuel gauge at start', ['Full', 'Three quarters', 'Half', 'Quarter', 'Reserve'], true),
                    $status('fuel_end', 'Fuel gauge at end', ['Full', 'Three quarters', 'Half', 'Quarter', 'Reserve'], false),
                    ['name' => 'fuel_added', 'label' => 'Fuel added (refill)', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'L'],
                    $text('purpose', 'Purpose', true),
                    $text('remarks', 'Remarks'),
                ],
            ],
            'temperature_monitoring' => [
                'name' => 'Temperature Monitoring Log',
                'code' => 'TMP',
                'description' => 'Temperature readings of a cold room, refrigerator or freezer. Set each reading\'s normal range before publishing: a reading outside it is kept and flagged, never refused.',
                'review_required' => false,
                'asset_types' => ['cold_storage', 'refrigerator', 'air_conditioner'],
                'recording' => ['mode' => 'scheduled', 'shifts' => 'optional', 'subject' => 'unit'],
                'fields' => [
                    $asset('unit', 'Room or unit'),
                    ['name' => 'temperature', 'label' => 'Temperature', 'type' => 'decimal', 'required' => true, 'unit' => '°C'],
                    ['name' => 'humidity', 'label' => 'Humidity', 'type' => 'decimal', 'required' => false, 'min' => '0', 'max' => '100', 'unit' => '%'],
                    $status('door', 'Door closed and sealed', ['Yes', 'No'], false, ['exception_values' => ['no'], 'exception_severity' => 'medium']),
                    $text('action', 'Action taken if out of range'),
                ],
            ],
            'equipment_inspection' => [
                'name' => 'Equipment Inspection Log',
                'code' => 'INS',
                'description' => 'A routine inspection of one asset, with a failed check raising an exception.',
                'review_required' => true,
                'asset_types' => ['generator', 'water_pump', 'air_conditioner', 'refrigerator', 'general_equipment', 'vehicle'],
                'recording' => ['mode' => 'scheduled', 'shifts' => 'optional', 'subject' => 'equipment'],
                'fields' => [
                    $asset('equipment', 'Equipment'),
                    $status('visual', 'Visual condition', ['Good', 'Minor issue', 'Damaged'], true, ['exception_values' => ['damaged'], 'exception_severity' => 'high']),
                    $status('safety', 'Safety devices working', ['Yes', 'No', 'Not applicable'], true, ['exception_values' => ['no'], 'exception_severity' => 'high']),
                    ['name' => 'leaks', 'label' => 'Leaks or unusual noise', 'type' => 'yes_no', 'required' => true, 'exception_values' => ['yes'], 'exception_severity' => 'medium'],
                    $text('findings', 'Findings'),
                    ['name' => 'photo', 'label' => 'Photo', 'type' => 'file', 'required' => false],
                ],
            ],
            'equipment_maintenance' => [
                'name' => 'Equipment Maintenance Log',
                'code' => 'MNT',
                'description' => 'Repairs and servicing on one asset.',
                'review_required' => true,
                'asset_types' => ['generator', 'water_pump', 'air_conditioner', 'refrigerator', 'general_equipment', 'vehicle'],
                'recording' => ['mode' => 'event', 'shifts' => 'off', 'subject' => 'equipment'],
                'fields' => [
                    $asset('equipment', 'Equipment'),
                    ['name' => 'technician', 'label' => 'Technician', 'type' => 'user', 'required' => true],
                    $status('maintenance_type', 'Maintenance type', ['Preventive', 'Corrective', 'Upgrade', 'Inspection']),
                    $text('problem', 'Problem description', true),
                    $text('action_taken', 'Action taken', true),
                    $text('parts', 'Parts replaced'),
                    $status('outcome', 'Outcome', ['Resolved', 'Partially resolved', 'Not resolved'], true, ['exception_values' => ['not_resolved'], 'exception_severity' => 'medium']),
                    ['name' => 'follow_up', 'label' => 'Follow-up required', 'type' => 'yes_no', 'required' => true, 'default' => false],
                    ['name' => 'attachments', 'label' => 'Attachments', 'type' => 'file', 'required' => false],
                ],
            ],
            'water_pump_operation' => [
                'name' => 'Water Pump Operation Log',
                'code' => 'PMP',
                'description' => 'Each pump run: hour meter, pressure and flow.',
                'review_required' => false,
                'asset_types' => ['water_pump'],
                'recording' => ['mode' => 'event', 'shifts' => 'optional', 'subject' => 'pump', 'activity' => [
                    'resource_field' => 'pump', 'measure' => 'hour_meter', 'start_field' => 'hours_start', 'end_field' => 'hours_end',
                    'actual_field' => null, 'refill_field' => 'fuel_added',
                ]],
                'fields' => [
                    $asset('pump', 'Pump', 'Water pump'),
                    ['name' => 'hours_start', 'label' => 'Hour meter at start', 'type' => 'decimal', 'required' => true, 'min' => '0', 'unit' => 'h'],
                    ['name' => 'hours_end', 'label' => 'Hour meter at end', 'type' => 'decimal', 'required' => true, 'min' => '0', 'unit' => 'h'],
                    ['name' => 'pressure', 'label' => 'Pressure', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'bar'],
                    ['name' => 'flow', 'label' => 'Flow', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'm³/h'],
                    ['name' => 'fuel_added', 'label' => 'Fuel added (refill)', 'type' => 'decimal', 'required' => false, 'min' => '0', 'unit' => 'L'],
                    $status('condition', 'Condition', ['Good', 'Needs attention', 'Faulty'], true, ['exception_values' => ['faulty'], 'exception_severity' => 'high']),
                    $text('remarks', 'Remarks'),
                ],
            ],
            'incident_report' => [
                'name' => 'General Incident Report',
                'code' => 'INC',
                'description' => 'Something that went wrong. A high or critical incident raises an exception, which can be investigated and followed by corrective tasks.',
                'review_required' => true,
                'recording' => ['mode' => 'event', 'shifts' => 'optional'],
                'location' => ['mode' => 'entry', 'required' => false, 'resource_required' => false],
                'fields' => [
                    ['name' => 'asset', 'label' => 'Asset involved', 'type' => 'resource', 'required' => false],
                    $status('incident_type', 'Type', ['Breakdown', 'Accident', 'Injury', 'Damage', 'Loss or theft', 'Safety hazard', 'Other']),
                    $status('severity', 'Severity', ['Low', 'Medium', 'High', 'Critical'], true, ['exception_values' => ['high', 'critical'], 'exception_severity' => 'high']),
                    $text('what_happened', 'What happened', true),
                    $text('immediate_action', 'Immediate action taken'),
                    ['name' => 'people_involved', 'label' => 'Person involved', 'type' => 'user', 'required' => false],
                    ['name' => 'evidence', 'label' => 'Photos or documents', 'type' => 'file', 'required' => false],
                ],
            ],
            'it_maintenance' => [
                'name' => 'IT Maintenance Log',
                'code' => 'ITM',
                'description' => 'Repairs and maintenance on IT equipment.',
                'review_required' => false,
                'recording' => ['mode' => 'on_demand', 'shifts' => 'off', 'subject' => 'equipment'],
                'fields' => [
                    $asset('equipment', 'Equipment'),
                    ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['name' => 'technician', 'label' => 'Technician', 'type' => 'user', 'required' => true],
                    $status('maintenance_type', 'Maintenance type', ['Preventive', 'Corrective', 'Upgrade', 'Inspection']),
                    $text('problem', 'Problem description', true),
                    $text('action_taken', 'Action taken', true),
                    $status('outcome', 'Outcome', ['Resolved', 'Partially resolved', 'Not resolved']),
                    ['name' => 'follow_up', 'label' => 'Follow-up required', 'type' => 'yes_no', 'required' => true, 'default' => false],
                    $text('remarks', 'Remarks'),
                    ['name' => 'attachments', 'label' => 'Attachments', 'type' => 'file', 'required' => false],
                ],
            ],
        ];
    }
}
