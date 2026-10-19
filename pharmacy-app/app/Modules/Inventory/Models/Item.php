<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\StorageCondition;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Something the organization stocks, buys and sells: what batches, order
 * lines and sale lines point at. The module named by $kind keeps the
 * item's own details (a medicine, for Pharmacy) under the same id and is
 * the one that writes it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property string $name
 * @property string|null $alias
 * @property string $unit
 * @property string|null $barcode
 * @property string|null $category
 * @property StorageCondition $storage
 * @property int $sell_price_minor
 * @property int $reorder_level
 * @property bool $restricted
 * @property bool $controlled
 * @property bool $tracks_expiry its batches carry an expiry date (medicines, food); otherwise none
 * @property bool $active
 */
#[UseFactory(ItemFactory::class)]
class Item extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['organization_id'];

    protected $attributes = ['storage' => 'room', 'tracks_expiry' => true];

    protected function casts(): array
    {
        return [
            'sell_price_minor' => 'integer',
            'reorder_level' => 'integer',
            'restricted' => 'boolean',
            'controlled' => 'boolean',
            'tracks_expiry' => 'boolean',
            'active' => 'boolean',
            'storage' => StorageCondition::class,
        ];
    }

    /**
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * @return HasMany<ItemPack, $this>
     */
    public function packs(): HasMany
    {
        return $this->hasMany(ItemPack::class)->orderBy('units');
    }

    /**
     * Items of these kinds only: an industry's own (App\Support\Industry).
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $kinds
     */
    public function scopeOfKind(Builder $query, array $kinds): void
    {
        $query->whereIn('kind', $kinds);
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
            ->orWhereRaw('LOWER(alias) LIKE ?', [$like])
            ->orWhere('barcode', trim($term)));
    }
}
