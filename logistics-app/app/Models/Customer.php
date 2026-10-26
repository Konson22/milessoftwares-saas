<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Who shipments are carried for and invoiced to.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property bool $active
 */
class Customer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['name', 'phone', 'email', 'address', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Name, phone or email containing the text.
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
            ->whereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(coalesce(phone, \'\')) like ?', [$like])
            ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$like]));
    }
}
