<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An implementing organization that runs part of a programme.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $kind
 * @property CarbonImmutable|null $archived_at
 */
class Partner extends Model
{
    protected $table = 'mel_partners';

    use TenantModel;

    public const KINDS = ['ngo', 'government', 'community', 'private', 'un_agency', 'other'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }
}
