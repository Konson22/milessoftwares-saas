<?php

namespace App\Modules\Structure\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country_code
 * @property string $status
 * @property string|null $manager_membership_id
 */
#[Fillable(['name', 'code', 'address', 'city', 'country_code', 'status'])]
#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
