<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use TenantModel;

    protected $table = 'document_sequences';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [];
    }
}
