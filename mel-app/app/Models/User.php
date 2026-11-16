<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A platform user who has signed in to this product. The id is the
 * platform subject (immutable); name and email come from the platform at
 * every sign-in. Nothing here grants access: the platform decides that on
 * every request (platform.access).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 */
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';
}
