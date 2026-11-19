<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file answering an entry's file field, on the private disk.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $log_entry_id
 * @property string $field
 * @property string $name
 * @property string $path
 * @property int $size
 * @property string|null $mime
 * @property string $uploaded_by
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Entry $entry
 */
class Attachment extends Model
{
    use TenantModel;

    protected $table = 'log_entry_attachments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'removed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }
}
