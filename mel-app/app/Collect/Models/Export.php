<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An export of a form's submissions (ADR-0012 §12): built on the queue,
 * kept on the private disk for seven days, downloaded only by the person
 * who asked for it while they still may export.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $form_id
 * @property string $requested_by
 * @property array<string, mixed> $filters
 * @property string $format
 * @property bool $includes_personal_data
 * @property string $status
 * @property int|null $rows
 * @property string|null $path
 * @property string|null $error
 * @property int $downloads
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property-read Form $form
 * @property-read User $requester
 */
class Export extends Model
{
    use TenantModel;

    protected $table = 'collect_exports';

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const FORMATS = ['csv', 'xlsx'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'includes_personal_data' => 'boolean',
            'rows' => 'integer',
            'downloads' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
