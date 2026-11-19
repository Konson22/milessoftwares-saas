<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a sheet's fields. A draft is edited freely; once
 * published it never changes again (a PostgreSQL trigger refuses it), and
 * every entry written against it is read through it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $log_sheet_id
 * @property int $version
 * @property string $status
 * @property list<array<string, mixed>> $fields
 * @property string $created_by
 * @property CarbonImmutable|null $published_at
 * @property string|null $published_by
 * @property-read LogSheet $sheet
 */
class LogSheetVersion extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'version' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }
}
