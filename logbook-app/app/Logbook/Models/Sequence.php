<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property int $year
 * @property int $last
 */
class Sequence extends Model
{
    use TenantModel;

    protected $table = 'log_sequences';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'last' => 'integer'];
    }
}
