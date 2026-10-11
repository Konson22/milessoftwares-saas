<?php

namespace App\Modules\Integration\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Identity\OpenId\SigningKey;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;

/**
 * Mints organization-bound event tokens: "application S may send events
 * about organization O to shared module M". Issued only while O is active
 * and entitled to both S and M. Five minutes; signed with the platform key,
 * so modules verify them against the platform JWKS (miles/shared-module-kit).
 */
final class EventTokenIssuer
{
    public const TTL_SECONDS = 300;

    /**
     * @return array{token: string, token_type: string, expires_in: int}
     *
     * @throws EventTokenRefused
     */
    public function issue(Application $source, Organization $organization, string $moduleCode): array
    {
        $module = Application::query()->where('code', $moduleCode)->first();

        if ($module === null || $module->kind !== ApplicationKind::SharedModule) {
            throw new EventTokenRefused('unknown_module', 'No shared module with that code.');
        }

        if ($organization->status !== OrganizationStatus::Active) {
            throw new EventTokenRefused('organization_inactive', 'The organization is not active.');
        }

        foreach ([$source, $module] as $application) {
            if (! $this->entitled($organization, $application)) {
                throw new EventTokenRefused('not_entitled', "The organization is not entitled to {$application->name}.");
            }
        }

        $now = new DateTimeImmutable;
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($this->privateKey()), InMemory::plainText('-'));

        $builder = $config->builder()
            ->issuedBy(PlatformHosts::url(PlatformHosts::auth()))
            ->permittedFor($module->code)
            ->relatedTo($source->code)
            ->identifiedBy((string) Str::uuid7())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+'.self::TTL_SECONDS.' seconds'))
            ->withClaim('org', $organization->id)
            ->withClaim('scope', 'events.write');

        if (($kid = SigningKey::kid()) !== null) {
            $builder = $builder->withHeader('kid', $kid);
        }

        return [
            'token' => $builder->getToken($config->signer(), $config->signingKey())->toString(),
            'token_type' => 'Bearer',
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    private function entitled(Organization $organization, Application $application): bool
    {
        return DB::table('entitlements')
            ->where('organization_id', $organization->id)
            ->where('application_id', $application->id)
            ->whereNull('feature_key')
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->exists();
    }

    /**
     * @return non-empty-string
     */
    private function privateKey(): string
    {
        $configured = config('passport.private_key');
        $path = Passport::keyPath('oauth-private.key');

        $key = match (true) {
            is_string($configured) && $configured !== '' => str_replace('\\n', "\n", $configured),
            is_file($path) => (string) file_get_contents($path),
            default => '',
        };

        if ($key === '') {
            throw new RuntimeException('The platform signing key is not configured.');
        }

        return $key;
    }
}
