<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use TenantModel;

    protected $table = 'procurement_audit_events';

    protected $guarded = ['id', 'organization_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
