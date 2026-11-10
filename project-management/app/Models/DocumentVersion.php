<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One file of a document, as uploaded. Never changed or removed: a correction is
 * a new version.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $document_id
 * @property int $version
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property string|null $note
 * @property string|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read User|null $uploader
 */
class DocumentVersion extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document versions are never changed.'));
        static::deleting(fn () => throw new LogicException('Document versions are never removed.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
