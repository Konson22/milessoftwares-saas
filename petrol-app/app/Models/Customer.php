<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $tax_id
 * @property int $credit_limit_minor
 * @property bool $active
 */
class Customer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const INDIVIDUAL = 'individual';

    public const CORPORATE = 'corporate';

    public const NGO = 'ngo';

    public const GOVERNMENT = 'government';

    public const FLEET = 'fleet';

    public const KINDS = [self::INDIVIDUAL, self::CORPORATE, self::NGO, self::GOVERNMENT, self::FLEET];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CreditEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }
}
