<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet of results: validated first (shown with its errors), then
 * applied in one transaction (App\Mel\Import).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $reporting_period_id
 * @property string $file_name
 * @property string $sha256
 * @property string|null $path
 * @property string $status
 * @property int $rows_total
 * @property int $rows_ready
 * @property int $rows_skipped
 * @property list<array{row: int, message: string}>|null $errors
 * @property string $created_by
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable $created_at
 * @property-read Programme $programme
 * @property-read ReportingPeriod $period
 * @property-read User $creator
 */
class ImportBatch extends Model
{
    protected $table = 'mel_import_batches';

    use TenantModel;

    public const VALIDATED = 'validated';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'rows_ready' => 'integer',
            'rows_skipped' => 'integer',
            'errors' => 'array',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<ReportingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
