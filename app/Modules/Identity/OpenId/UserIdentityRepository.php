<?php

namespace App\Modules\Identity\OpenId;

use App\Models\User;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;

final class UserIdentityRepository implements IdentityRepositoryInterface
{
    public function getByIdentifier(string $identifier): IdentityEntityInterface
    {
        return new UserIdentity(User::query()->findOrFail($identifier));
    }
}
