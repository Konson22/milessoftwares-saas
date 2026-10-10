<?php

namespace App\Modules\Applications\Models;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry entry for a SaaS product or shared module. Single source of truth
 * for catalog status, workspace routing and integration settings.
 *
 * @property string $id
 * @property non-empty-string $code enforced by applications_code_check
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property ApplicationKind $kind
 * @property ApplicationStatus $status
 * @property string|null $route_prefix
 * @property string|null $gateway_upstream
 * @property string|null $integration_endpoint
 * @property string $provisioning_method
 * @property array<string, mixed>|null $capabilities
 * @property string|null $current_catalog_version_id
 * @property int $sort_order
 */
#[Fillable([
    'code', 'name', 'description', 'category', 'kind', 'status', 'route_prefix',
    'gateway_upstream', 'integration_endpoint', 'provisioning_method', 'capabilities', 'sort_order',
])]
class Application extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ApplicationKind::class,
            'status' => ApplicationStatus::class,
            'capabilities' => 'array',
        ];
    }
}
