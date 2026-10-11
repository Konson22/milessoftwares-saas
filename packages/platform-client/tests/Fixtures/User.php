<?php

namespace Miles\PlatformClient\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Local mirror of a platform user, as a product would define it.
 */
class User extends Authenticatable
{
    use HasUuids;

    protected $table = 'users';
}
