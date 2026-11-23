<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{type: string, value: string, label?: string}> $steps
 */
class ApprovalRule extends Model
{
    use TenantModel;

    protected $table = 'approval_rules';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'active' => 'boolean', 'min_base_minor' => 'integer', 'max_base_minor' => 'integer'];
    }
}
