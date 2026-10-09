<?php

namespace App\Modules\Identity\OpenId;

use App\Models\User;
use InvalidArgumentException;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

/**
 * The OIDC subject. `sub` is the immutable user UUID; claims are filtered by
 * the granted scopes (profile, email) before they reach the id_token.
 */
final class UserIdentity implements IdentityEntityInterface
{
    use EntityTrait;

    public function __construct(private readonly User $user)
    {
        $id = (string) $user->getKey();

        if ($id === '') {
            throw new InvalidArgumentException('An OpenID identity requires a persisted user.');
        }

        $this->setIdentifier($id);
    }

    public function getIdentifier(): string
    {
        return (string) $this->identifier;
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    public function getClaims(array $scopes = []): array
    {
        return [
            'name' => $this->user->name,
            'updated_at' => $this->user->updated_at?->getTimestamp(),
            'email' => $this->user->email,
            'email_verified' => $this->user->email_verified_at !== null,
        ];
    }
}
