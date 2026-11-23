<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 */
class Document extends Model
{
    use TenantModel;

    protected $table = 'procurement_documents';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
