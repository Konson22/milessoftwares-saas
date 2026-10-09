<?php

namespace App\Modules\Identity\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * Passport client with two platform rules:
 *
 * - Redirect URIs are the client's own list plus the exact URIs registered for
 *   it in application_client_redirect_uris (per-tenant rows written by
 *   provisioning). Matching stays exact; there are no wildcards.
 * - First-party clients (no owner) skip the consent screen. Every client is
 *   registered by the platform today, so consent is only shown to third-party
 *   clients, should they ever exist.
 */
class OAuthClient extends Client
{
    /**
     * @return Attribute<list<string>, never>
     */
    protected function redirectUris(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes): array {
                $own = empty($value) ? [] : $this->fromJson($value);

                // Registered rows grow with every tenant. Matching is exact, so
                // only the URI this request asks for needs to be looked up.
                $requested = request()->input('redirect_uri');
                if (! is_string($requested) || $requested === '') {
                    return array_values($own);
                }

                $registered = DB::table('application_client_redirect_uris as uris')
                    ->join('application_clients as clients', 'clients.id', '=', 'uris.application_client_id')
                    ->where('clients.oauth_client_ref', $attributes['id'] ?? null)
                    ->where('clients.status', 'active')
                    ->where('uris.uri', $requested)
                    ->pluck('uris.uri')
                    ->all();

                return array_values(array_unique([...$own, ...$registered]));
            },
        );
    }

    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
