<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * A file on a task (its brief, or evidence of the work) or on an exception
 * (evidence of the resolution). Private disk, served only through an
 * authorized controller.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $task_id
 * @property string|null $exception_id
 * @property string $kind
 * @property string $name
 * @property string $path
 * @property int $size
 * @property string|null $mime
 * @property string $sha256
 * @property string $uploaded_by
 */
class WorkFile extends Model
{
    use TenantModel;

    protected $table = 'log_work_files';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
