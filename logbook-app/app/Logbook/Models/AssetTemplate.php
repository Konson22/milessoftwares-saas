<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's own asset template (ADR-0031 §3): the defaults a new
 * asset starts from. Editing it raises its version; assets registered
 * earlier keep the snapshot they were made with.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $type
 * @property string $kind
 * @property string|null $description
 * @property list<array{name: string, label: string, type: string, unit?: string|null}>|null $fields
 * @property list<string>|null $measurements
 * @property array{method: string, fuel_type: string}|null $fuel
 * @property list<string>|null $suggested_sheets
 * @property int $version
 * @property bool $active
 * @property string $created_by
 */
class AssetTemplate extends Model
{
    use TenantModel;

    protected $table = 'log_asset_templates';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true, 'version' => 1, 'kind' => 'asset'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'fields' => 'array', 'measurements' => 'array', 'fuel' => 'array', 'suggested_sheets' => 'array'];
    }
}
