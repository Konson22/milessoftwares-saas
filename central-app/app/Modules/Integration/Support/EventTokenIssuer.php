<?php

namespace App\Modules\Integration\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Identity\OpenId\SigningKey;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
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
 * and entitled to both S and M.
 *
 * One narrow exception lets a shared module report back to a product
 * (MODULE_TO_PRODUCT): Finance may send Projects the outcomes of payment
 * requests Projects handed it (ADR-0017 §6, docs/finance/finance-projects-contract.md).
 * Only the listed module may address the listed product, never the other
 * way round, never product to product. Five minutes; signed with the platform key,
 * so modules verify them against the platform JWKS (miles/shared-module-kit).
 */
final class EventTokenIssuer
{
    public const TTL_SECONDS = 300;

    /**
     * Shared module code => the product codes it may send events to.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_TO_PRODUCT = ['finance' => ['projects']];

    /**
     * Product code => the product codes whose integration API it may read
     * (scope integration.read, issueIntegration). Projects and M&E read
     * each other's minimal APIs for organizations entitled to both
     * (ADR-0019 §9); nothing else reads a product.
     *
     * @var array<string, list<string>>
     */
    public const PRODUCT_PAIRS = ['projects' => ['mel'], 'mel' => ['projects']];

    /**
     * @return array{token: string, token_type: string, expires_in: int}
     *
     * @throws EventTokenRefused
     */
    public function issue(Application $source, Organization $organization, string $moduleCode): array
    {
        $module = Application::query()->where('code', $moduleCode)->first();

        if ($module === null || ($module->kind !== ApplicationKind::SharedModule && ! self::reportsBack($source, $module))) {
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

        return $this->sign($source, $organization, $module->code, 'events.write');
    }

    /**
     * A read token for another product's integration API (ADR-0019 §9):
     * "application S may read product P's integration API for organization
     * O", only for a listed pair (PRODUCT_PAIRS), while O is active and
     * entitled to both. With an acting user, the token also names them
     * (claim act), once they are an active member of O: the receiver then
     * checks that user's own access in its own catalog. A service token
     * alone never grants a user's reads.
     *
     * @return array{token: string, token_type: string, expires_in: int}
     *
     * @throws EventTokenRefused
     */
    public function issueIntegration(Application $source, Organization $organization, string $audienceCode, ?string $actingUserId = null): array
    {
        $audience = Application::query()->where('code', $audienceCode)->first();

        if ($audience === null || $audience->id === $source->id || ! in_array($audience->code, self::PRODUCT_PAIRS[$source->code] ?? [], true)) {
            throw new EventTokenRefused('unknown_partner', 'This application does not read that one.');
        }

        if ($organization->status !== OrganizationStatus::Active) {
            throw new EventTokenRefused('organization_inactive', 'The organization is not active.');
        }

        foreach ([$source, $audience] as $application) {
            if (! $this->entitled($organization, $application)) {
                // An entitlement that ran out reads differently from none at all.
                throw $this->expired($organization, $application)
                    ? new EventTokenRefused('entitlement_expired', "The organization's {$application->name} subscription has expired.")
                    : new EventTokenRefused('not_entitled', "The organization is not entitled to {$application->name}.");
            }
        }

        if ($actingUserId !== null && ! $this->activeMember($organization, $actingUserId)) {
            throw new EventTokenRefused('not_member', 'The acting user is not an active member of the organization.');
        }

        return $this->sign($source, $organization, $audience->code, 'integration.read', $actingUserId);
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int}
     */
    private function sign(Application $source, Organization $organization, string $audienceCode, string $scope, ?string $actingUserId = null): array
    {
        $now = new DateTimeImmutable;
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($this->privateKey()), InMemory::plainText('-'));

        $builder = $config->builder()
            ->issuedBy(PlatformHosts::url(PlatformHosts::auth()))
            ->permittedFor($audienceCode)
            ->relatedTo($source->code)
            ->identifiedBy((string) Str::uuid7())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+'.self::TTL_SECONDS.' seconds'))
            ->withClaim('org', $organization->id)
            ->withClaim('scope', $scope);

        if ($actingUserId !== null) {
            $builder = $builder->withClaim('act', $actingUserId);
        }

        if (($kid = SigningKey::kid()) !== null) {
            $builder = $builder->withHeader('kid', $kid);
        }

        return [
            'token' => $builder->getToken($config->signer(), $config->signingKey())->toString(),
            'token_type' => 'Bearer',
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    private function activeMember(Organization $organization, string $userId): bool
    {
        if (! Str::isUuid($userId)) {
            return false;
        }

        return (bool) app(TenantContext::class)->run($organization, fn () => OrganizationMembership::query()
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active)
            ->exists());
    }

    /**
     * The source is a shared module listed as allowed to report to this product.
     */
    private static function reportsBack(Application $source, Application $audience): bool
    {
        return $source->kind === ApplicationKind::SharedModule
            && $source->id !== $audience->id
            && in_array($audience->code, self::MODULE_TO_PRODUCT[$source->code] ?? [], true);
    }

    private function expired(Organization $organization, Application $application): bool
    {
        return DB::table('entitlements')
            ->where('organization_id', $organization->id)
            ->where('application_id', $application->id)
            ->whereNull('feature_key')
            ->whereNull('revoked_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->exists();
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
