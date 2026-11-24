<?php

namespace App\Logbook;

use App\Logbook\Models\AssetTemplate;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\ResourceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asset templates (ADR-0031 §3): the defaults a new asset starts from —
 * its category, the details asked about it, what is measured on it, a
 * default fuel estimate, and the starter log sheets suggested for it.
 *
 * Starter templates live here in code; an organization adds its own in
 * log_asset_templates. Nothing is rigid: every value is changed freely
 * when registering. An asset keeps a snapshot of the template it was
 * registered from, so changing a template never changes existing assets.
 */
final class AssetTemplates
{
    public const FIELD_TYPES = ['text', 'number', 'date'];

    public const MAX_FIELDS = 20;

    /**
     * @return array<string, array{name: string, type: string, category: string, kind: string, description: string, fields: list<array{name: string, label: string, type: string, unit?: string|null}>, measurements: list<string>, fuel: array{method: string, fuel_type: string}|null, suggested_sheets: list<string>}>
     */
    public static function starters(): array
    {
        $f = fn (string $label, string $type = 'text', ?string $unit = null) => ['name' => Fields::nameFor($label), 'label' => $label, 'type' => $type, ...($unit !== null ? ['unit' => $unit] : [])];

        return [
            'generator' => [
                'name' => 'Generator', 'type' => 'Diesel generator', 'category' => 'generators', 'kind' => 'asset',
                'description' => 'A fuel generator: hours run, fuel, load and inspections.',
                'fields' => [$f('Rated power', 'number', 'kVA'), $f('Fuel tank capacity', 'number', 'L'), $f('Installed on', 'date')],
                'measurements' => ['Running hours', 'Fuel', 'Load', 'Condition'],
                'fuel' => ['method' => 'hours', 'fuel_type' => 'diesel'],
                'suggested_sheets' => ['generator_operation', 'equipment_inspection', 'equipment_maintenance', 'incident_report'],
            ],
            'vehicle' => [
                'name' => 'Vehicle', 'type' => 'Car', 'category' => 'vehicles', 'kind' => 'asset',
                'description' => 'A car, truck or motorbike: trips, odometer, fuel and incidents.',
                'fields' => [$f('Year', 'number'), $f('Fuel tank capacity', 'number', 'L'), $f('Insurance expires', 'date')],
                'measurements' => ['Distance', 'Trip duration', 'Fuel'],
                'fuel' => ['method' => 'distance', 'fuel_type' => 'diesel'],
                'suggested_sheets' => ['vehicle_movement', 'equipment_maintenance', 'incident_report'],
            ],
            'cold_storage' => [
                'name' => 'Cold Storage Room', 'type' => 'Cold storage room', 'category' => 'cooling', 'kind' => 'monitored_location',
                'description' => 'A monitored place, such as a cold room or store. Its cooling units are registered as their own assets.',
                'fields' => [$f('Capacity', 'number', 'm³'), $f('Stored goods')],
                'measurements' => ['Temperature', 'Humidity'],
                'fuel' => null,
                'suggested_sheets' => ['temperature_monitoring', 'incident_report'],
            ],
            'air_conditioner' => [
                'name' => 'Air Conditioner', 'type' => 'Air conditioner', 'category' => 'cooling', 'kind' => 'asset',
                'description' => 'A cooling unit: temperature, inspections and servicing.',
                'fields' => [$f('Cooling capacity', 'number', 'BTU'), $f('Refrigerant')],
                'measurements' => ['Temperature', 'Condition'],
                'fuel' => null,
                'suggested_sheets' => ['temperature_monitoring', 'equipment_inspection', 'equipment_maintenance'],
            ],
            'refrigerator' => [
                'name' => 'Refrigerator / Freezer', 'type' => 'Refrigerator', 'category' => 'cooling', 'kind' => 'asset',
                'description' => 'A refrigerator or freezer: temperature readings and servicing.',
                'fields' => [$f('Volume', 'number', 'L')],
                'measurements' => ['Temperature'],
                'fuel' => null,
                'suggested_sheets' => ['temperature_monitoring', 'equipment_maintenance'],
            ],
            'water_pump' => [
                'name' => 'Water Pump', 'type' => 'Water pump', 'category' => 'water', 'kind' => 'asset',
                'description' => 'A pump: hours run, pressure, flow and fuel when it has an engine.',
                'fields' => [$f('Power', 'number', 'kW'), $f('Source')],
                'measurements' => ['Running hours', 'Pressure', 'Flow'],
                'fuel' => ['method' => 'hours', 'fuel_type' => 'diesel'],
                'suggested_sheets' => ['water_pump_operation', 'equipment_inspection', 'equipment_maintenance'],
            ],
            'general_equipment' => [
                'name' => 'General Equipment', 'type' => 'Equipment', 'category' => 'other', 'kind' => 'asset',
                'description' => 'Any other equipment: inspections, maintenance and incidents.',
                'fields' => [],
                'measurements' => [],
                'fuel' => null,
                'suggested_sheets' => ['equipment_inspection', 'equipment_maintenance', 'incident_report'],
            ],
        ];
    }

    /**
     * Every template a manager can register from: starters ("starter:key")
     * and the organization's own active ones (by id).
     *
     * @return list<array<string, mixed>>
     */
    public static function all(bool $withInactive = false): array
    {
        $out = [];
        foreach (self::starters() as $key => $t) {
            $out[] = self::starterRow($key, $t);
        }
        $own = AssetTemplate::query()->when(! $withInactive, fn ($q) => $q->where('active', true))->orderBy('name')->get();
        foreach ($own as $t) {
            $out[] = self::row($t);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (str_starts_with($key, 'starter:')) {
            $starter = self::starters()[substr($key, 8)] ?? null;

            return $starter === null ? null : self::starterRow(substr($key, 8), $starter);
        }
        $own = Str::isUuid($key) ? AssetTemplate::query()->find($key) : null;

        return $own === null ? null : self::row($own);
    }

    /**
     * A starter, with the workspace category it puts new assets in (the
     * default it names, while the workspace still has it).
     *
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private static function starterRow(string $key, array $t): array
    {
        ResourceCategories::ensure();
        $categoryId = ResourceCategory::query()->where('key', $t['category'])->where('active', true)->value('id');

        return ['key' => "starter:{$key}", 'starter' => true, 'version' => 1, 'active' => true, 'id' => null, ...$t, 'categoryId' => $categoryId];
    }

    /** @return array<string, mixed> */
    public static function row(AssetTemplate $t): array
    {
        return [
            'key' => $t->id, 'id' => $t->id, 'starter' => false, 'version' => $t->version, 'active' => $t->active,
            'name' => $t->name, 'type' => $t->type, 'categoryId' => $t->category_id, 'kind' => $t->kind, 'description' => (string) $t->description,
            'fields' => $t->fields ?? [], 'measurements' => $t->measurements ?? [], 'fuel' => $t->fuel,
            'suggested_sheets' => $t->suggested_sheets ?? [],
        ];
    }

    /**
     * What an asset keeps of the template it was registered from.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public static function snapshot(array $template): array
    {
        return [
            'key' => $template['key'], 'name' => $template['name'], 'version' => $template['version'],
            'fields' => $template['fields'], 'measurements' => $template['measurements'], 'suggested_sheets' => $template['suggested_sheets'],
        ];
    }

    /**
     * Saves an organization template. A change raises its version; assets
     * already registered keep the snapshot they were made with.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(?AssetTemplate $template, array $data, User $by): AssetTemplate
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the template a name (up to 120 characters).';
        } elseif (AssetTemplate::query()->where('name', $name)->when($template, fn ($q) => $q->whereKeyNot($template->id))->exists()
            || collect(self::starters())->contains(fn ($s) => mb_strtolower($s['name']) === mb_strtolower($name))) {
            $errors['name'] = 'Another template has this name.';
        }
        if ($type === '' || mb_strlen($type) > 48) {
            $errors['type'] = 'Give the type new assets take (up to 48 characters).';
        }
        $categoryId = is_string($data['category_id'] ?? null) && $data['category_id'] !== '' ? $data['category_id'] : null;
        if ($categoryId !== null && ! (Str::isUuid($categoryId) && ResourceCategory::query()->whereKey($categoryId)->exists())) {
            $errors['category_id'] = 'Choose one of the categories.';
        }
        $kind = (string) ($data['kind'] ?? 'asset');
        if (! in_array($kind, LogResource::KINDS, true)) {
            $errors['kind'] = 'Choose a physical asset or a monitored location.';
        }
        [$fields, $fieldErrors] = self::fieldsInput($data['fields'] ?? []);
        $errors += $fieldErrors;
        $measurements = array_values(array_unique(array_filter(array_map(fn ($m) => is_string($m) ? mb_substr(trim($m), 0, 60) : '', (array) ($data['measurements'] ?? [])))));
        $fuel = null;
        if (is_array($data['fuel'] ?? null) && in_array($data['fuel']['method'] ?? null, ['distance', 'hours'], true)) {
            $fuel = ['method' => $data['fuel']['method'], 'fuel_type' => in_array($data['fuel']['fuel_type'] ?? null, FuelRate::FUELS, true) ? $data['fuel']['fuel_type'] : 'diesel'];
        }
        $sheets = array_values(array_intersect(array_keys(StarterTemplates::all()), (array) ($data['suggested_sheets'] ?? [])));
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($template, $name, $type, $categoryId, $kind, $data, $fields, $measurements, $fuel, $sheets, $by) {
            $values = [
                'name' => $name, 'type' => $type, 'category_id' => $categoryId ?? ResourceCategories::guess($type)?->id, 'kind' => $kind,
                'description' => is_string($data['description'] ?? null) && trim($data['description']) !== '' ? mb_substr(trim($data['description']), 0, 2000) : null,
                'fields' => $fields, 'measurements' => $measurements, 'fuel' => $fuel, 'suggested_sheets' => $sheets,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : ($template->active ?? true),
            ];
            if ($template === null) {
                $template = AssetTemplate::query()->create([...$values, 'created_by' => $by->id]);
                AuditTrail::record('asset_template.created', $template, $by, ['name' => $name]);
            } else {
                $template->fill($values);
                if ($template->isDirty()) {
                    $template->version++;
                    $template->save();
                    AuditTrail::record('asset_template.updated', $template, $by, ['version' => $template->version]);
                }
            }

            return $template;
        });
    }

    /**
     * Detail fields of a template, checked: a label, a type, an optional unit.
     *
     * @return array{0: list<array{name: string, label: string, type: string, unit?: string}>, 1: array<string, string>}
     */
    public static function fieldsInput(mixed $input): array
    {
        $fields = [];
        $errors = [];
        $names = [];
        foreach (array_values(is_array($input) ? $input : []) as $i => $field) {
            $label = is_array($field) && is_string($field['label'] ?? null) ? trim($field['label']) : '';
            if ($label === '') {
                $errors["fields.{$i}.label"] = 'Each detail needs a label.';

                continue;
            }
            $name = Fields::nameFor($label);
            if (isset($names[$name])) {
                $errors["fields.{$i}.label"] = 'Two details have this label.';

                continue;
            }
            $names[$name] = true;
            $type = in_array($field['type'] ?? null, self::FIELD_TYPES, true) ? $field['type'] : 'text';
            $unit = is_string($field['unit'] ?? null) && trim($field['unit']) !== '' ? mb_substr(trim($field['unit']), 0, 20) : null;
            $fields[] = ['name' => $name, 'label' => mb_substr($label, 0, 60), 'type' => $type, ...($unit !== null ? ['unit' => $unit] : [])];
        }
        if (count($fields) > self::MAX_FIELDS) {
            $errors['fields'] = 'At most '.self::MAX_FIELDS.' details.';
        }

        return [$fields, $errors];
    }

    /**
     * An asset's details, as entered: the template's details plus any the
     * manager added. Each is {label, value, unit?}, keyed by name; empty
     * values are dropped.
     *
     * @return array{0: array<string, array{label: string, value: string, unit?: string}>, 1: array<string, string>}
     */
    public static function metadataInput(mixed $input): array
    {
        $out = [];
        $errors = [];
        foreach (array_values(is_array($input) ? $input : []) as $i => $row) {
            $label = is_array($row) && is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $value = is_array($row) && is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';
            if ($value === '') {
                continue;
            }
            if ($label === '') {
                $errors["metadata.{$i}.label"] = 'Name this detail.';

                continue;
            }
            if (mb_strlen($value) > 500) {
                $errors["metadata.{$i}.value"] = 'Up to 500 characters.';

                continue;
            }
            $unit = is_string($row['unit'] ?? null) && trim($row['unit']) !== '' ? mb_substr(trim($row['unit']), 0, 20) : null;
            $out[Fields::nameFor($label)] = ['label' => mb_substr($label, 0, 60), 'value' => $value, ...($unit !== null ? ['unit' => $unit] : [])];
        }
        if (count($out) > 40) {
            $errors['metadata'] = 'At most 40 details.';
        }

        return [$out, $errors];
    }
}
