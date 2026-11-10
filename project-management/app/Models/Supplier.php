<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone the organization buys from, named on purchase orders and quotes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $source_app
 * @property string|null $source_supplier_id
 * @property string|null $email
 * @property string|null $phone
 */
class Supplier extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];
}
