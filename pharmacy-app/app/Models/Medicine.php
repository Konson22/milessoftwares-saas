<?php

namespace App\Models;

use App\Enums\MedicineForm;
use Database\Factories\MedicineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A medicine in the organization's catalogue.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $generic_name
 * @property MedicineForm $form
 * @property string|null $strength
 * @property string $unit
 * @property string|null $barcode
 * @property bool $prescription_only
 * @property bool $controlled
 * @property int $sell_price_minor
 * @property int $reorder_level
 * @property bool $active
 */
class Medicine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MedicineFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name', 'generic_name', 'form', 'strength', 'unit', 'barcode',
        'prescription_only', 'controlled', 'sell_price_minor', 'reorder_level', 'active',
    ];

    protected function casts(): array
    {
        return [
            'form' => MedicineForm::class,
            'prescription_only' => 'boolean',
            'controlled' => 'boolean',
            'sell_price_minor' => 'integer',
            'reorder_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * "Amoxicillin 500 mg capsule"
     */
    public function label(): string
    {
        return trim($this->name.' '.($this->strength ?? '').' '.$this->form->value);
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(generic_name) LIKE ?', [$like])
            ->orWhere('barcode', trim($term)));
    }
}
