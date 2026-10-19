<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A file kept on someone's record: a contract, permit, licence, ID copy.
 * The file itself is on the private hr_documents disk and is only served
 * through a short-lived signed link (DocumentController).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_id
 * @property DocumentCategory $category
 * @property string $title
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 * @property string|null $note
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $uploaded_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_by
 * @property CarbonImmutable $created_at
 * @property-read Employee $employee
 * @property-read User $uploader
 * @property-read User|null $remover
 */
class Document extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DISK = 'hr_documents';

    protected $table = 'employee_documents';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'size_bytes' => 'integer',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
