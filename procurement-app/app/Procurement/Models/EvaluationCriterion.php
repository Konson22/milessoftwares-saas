<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $sourcing_event_id
 * @property string $name
 * @property string $kind pass_fail or score
 * @property bool $mandatory
 * @property int|null $max_score
 */
class EvaluationCriterion extends Model
{
    use TenantModel;

    protected $table = 'evaluation_criteria';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['mandatory' => 'boolean', 'max_score' => 'integer', 'sort' => 'integer'];
    }
}
