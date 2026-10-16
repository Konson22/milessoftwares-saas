<?php

namespace App\Modules\Pharmacy\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Pharmacy\Enums\MedicineForm;
use Database\Factories\MedicineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A medicine in the organization's catalogue: the pharmacy's details of an
 * Inventory item, under the item's id. What every module needs (unit,
 * price, barcode, flags) lives on the item; the medicine reads and writes
 * those attributes through it (ON_ITEM) and saves both together, keeping
 * the item's name equal to label().
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
 * @property-read Item $item
 */
#[UseFactory(MedicineFactory::class)]
class Medicine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MedicineFactory> */
    use HasFactory;

    use HasUuids;

    public const KIND = 'medicine';

    /**
     * Medicine attribute => item column.
     */
    private const ON_ITEM = [
        'generic_name' => 'alias',
        'unit' => 'unit',
        'barcode' => 'barcode',
        'prescription_only' => 'restricted',
        'controlled' => 'controlled',
        'sell_price_minor' => 'sell_price_minor',
        'reorder_level' => 'reorder_level',
        'active' => 'active',
    ];

    protected $fillable = [
        'name', 'generic_name', 'form', 'strength', 'unit', 'barcode',
        'prescription_only', 'controlled', 'sell_price_minor', 'reorder_level', 'active',
    ];

    protected $with = ['item'];

    protected function casts(): array
    {
        return [
            'form' => MedicineForm::class,
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'id');
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
        return $this->hasMany(Batch::class, 'item_id');
    }

    public function getAttribute($key): mixed
    {
        return isset(self::ON_ITEM[$key])
            ? $this->itemRecord()->getAttribute(self::ON_ITEM[$key])
            : parent::getAttribute($key);
    }

    public function setAttribute($key, $value): mixed
    {
        if (isset(self::ON_ITEM[$key])) {
            $this->itemRecord()->setAttribute(self::ON_ITEM[$key], $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * The item first (its id is the medicine's), then the medicine.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options) {
            $this->id ??= $this->newUniqueId();

            $item = $this->itemRecord();
            $item->id = $this->id;
            $item->kind = self::KIND;
            $item->name = $this->label();
            $item->save();

            return parent::save($options);
        });
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
            ->orWhereHas('item', fn (Builder $item) => $item
                ->whereRaw('LOWER(alias) LIKE ?', [$like])
                ->orWhere('barcode', trim($term))));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereRelation('item', 'active', true);
    }

    private function itemRecord(): Item
    {
        $item = $this->relationLoaded('item') ? $this->getRelation('item') : null;

        if (! $item instanceof Item) {
            $item = ($this->exists ? $this->item()->first() : null) ?? new Item;
            $this->setRelation('item', $item);
        }

        return $item;
    }
}
