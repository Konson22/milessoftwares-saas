<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file of a report to a funder, on the private disk. The funder sees it once
 * the report is submitted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_report_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $uploaded_by
 */
class GrantReportDocument extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
