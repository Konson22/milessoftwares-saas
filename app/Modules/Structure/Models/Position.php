<?php

namespace App\Modules\Structure\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Job title. Business structure only; grants no permissions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $department_id
 * @property string $title
 * @property string $code
 * @property string $status
 */
#[Fillable(['department_id', 'title', 'code', 'status'])]
class Position extends Model
{
    use BelongsToOrganization, HasUuids;
}
