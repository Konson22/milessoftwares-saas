<?php

namespace App\Modules\Provisioning\Models;

use App\Modules\Applications\Models\Application;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to one product. `running` means the product answered 202 and
 * will report completion through the provisioning callback.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $provisioning_run_id
 * @property string|null $application_id
 * @property string $step_key
 * @property string $status pending|running|succeeded|failed|skipped
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $completed_at
 * @property-read ProvisioningRun $run
 * @property-read Application|null $application
 */
class ProvisioningStep extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ProvisioningRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class, 'provisioning_run_id');
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
