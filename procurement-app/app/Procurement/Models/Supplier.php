<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use TenantModel;

    protected $table = 'suppliers';

    protected $guarded = ['id', 'organization_id'];

    public const TYPES = ['company', 'individual', 'ngo', 'government', 'other'];

    public const STATUSES = ['active', 'inactive', 'suspended'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    protected function casts(): array
    {
        return ['categories' => 'array'];
    }
}
