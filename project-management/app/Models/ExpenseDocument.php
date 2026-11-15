<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting document (receipt, invoice, quote) on an expense, stored on
 * the private disk under the organization's folder and only downloaded
 * through ExpenseController::document.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $expense_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read Expense $expense
 */
class ExpenseDocument extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    public const UPDATED_AT = null;

    /** What can be attached, and how big (kilobytes). */
    public const RULES = ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Seen only with the project its Expense belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['expense_id', Expense::class];
    }
}
