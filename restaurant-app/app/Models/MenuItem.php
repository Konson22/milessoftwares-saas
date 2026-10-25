<?php

namespace App\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A dish or a drink on the restaurant's menu, shared by every branch. A
 * dish is made to order and keeps no stock; a drink is bought and sold as
 * it is, and counted at each branch (StockLevel).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $kind dish, drink
 * @property string $name
 * @property string|null $section
 * @property string $unit
 * @property int $price_minor
 * @property string|null $barcode
 * @property int $reorder_level
 * @property bool $active
 */
class MenuItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    use HasUuids;

    public const DISH = 'dish';

    public const DRINK = 'drink';

    public const KINDS = [self::DISH, self::DRINK];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'reorder_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function tracksStock(): bool
    {
        return $this->kind === self::DRINK;
    }

    /**
     * Name, section or barcode containing the text.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(section) LIKE ?', [$like])
            ->orWhere('barcode', $text));
    }
}
