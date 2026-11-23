<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * One evaluator's result for one quotation on one criterion.
 *
 * @property string $id
 * @property string $quotation_id
 * @property string $criterion_id
 * @property string $evaluator_id
 * @property string $evaluator_name
 * @property bool|null $passed
 * @property string|null $score
 */
class EvaluationScore extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['passed' => 'boolean'];
    }
}
