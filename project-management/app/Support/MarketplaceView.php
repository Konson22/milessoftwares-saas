<?php

namespace App\Support;

use App\Actions\Marketplace;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\FundingListing;
use App\Models\MarketplaceDocument;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a donor sees in the funding marketplace, across organizations: only
 * published profiles and listings of active organizations, and only the fields
 * each organization typed for the public. The single place the marketplace
 * reads other organizations; it never loads a project, a budget or a grant.
 */
final class MarketplaceView
{
    /**
     * @param  array{q?: string|null, sector?: string|null, country?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public static function organizations(array $filters): array
    {
        $query = OrganizationProfile::visible()->with('organization:id,slug,name');
        self::text($query, $filters['q'] ?? null, ['summary', 'description', 'location'], 'organization');
        if (filled($filters['sector'] ?? null)) {
            $query->whereJsonContains('sectors', $filters['sector']);
        }
        if (filled($filters['country'] ?? null)) {
            $query->where('country_code', mb_strtoupper((string) $filters['country']));
        }

        $counts = FundingListing::visible()->selectRaw('organization_id, count(*) as listings')->groupBy('organization_id')->pluck('listings', 'organization_id');

        return array_values($query->get()->sortBy(fn (OrganizationProfile $profile) => mb_strtolower($profile->organization->name))
            ->map(fn (OrganizationProfile $profile) => [
                ...self::card($profile),
                'listings' => (int) ($counts[$profile->organization_id] ?? 0),
            ])->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function organization(string $slug): ?array
    {
        $profile = OrganizationProfile::visible()->with('organization:id,slug,name,legal_name')
            ->whereHas('organization', fn ($query) => $query->where('slug', $slug))->first();
        if ($profile === null) {
            return null;
        }

        return [
            ...self::card($profile),
            'legalName' => $profile->organization->legal_name,
            'description' => $profile->description,
            'mission' => $profile->mission,
            'vision' => $profile->vision,
            'operatingAreas' => $profile->operating_areas ?? [],
            'registrationNumber' => $profile->registration_number,
            'registrationAuthority' => $profile->registration_authority,
            'website' => $profile->website,
            'contactEmail' => $profile->contact_email,
            'contactPhone' => $profile->contact_phone,
            'achievements' => $profile->achievements,
            'documents' => self::documents($profile->organization, MarketplaceDocument::PROFILE, $profile->id),
            'listings' => array_map(fn (FundingListing $listing) => self::listingCard($listing),
                FundingListing::visible()->with('organization:id,slug,name')->where('organization_id', $profile->organization_id)->orderByDesc('published_at')->get()->all()),
        ];
    }

    /**
     * @param  array{q?: string|null, sector?: string|null, country?: string|null, status?: string|null, currency?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public static function listings(array $filters): array
    {
        $query = FundingListing::visible()->with('organization:id,slug,name');
        self::text($query, $filters['q'] ?? null, ['title', 'summary', 'location'], 'organization');
        foreach (['sector' => 'sector', 'status' => 'funding_status'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (filled($filters['country'] ?? null)) {
            $query->where('country_code', mb_strtoupper((string) $filters['country']));
        }
        if (filled($filters['currency'] ?? null)) {
            $query->where('currency', mb_strtoupper((string) $filters['currency']));
        }

        return array_values(array_map(fn (FundingListing $listing) => self::listingCard($listing), $query->orderByDesc('published_at')->get()->all()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function listing(string $id): ?array
    {
        $listing = FundingListing::visible()->with('organization:id,slug,name')->find($id);
        if ($listing === null) {
            return null;
        }

        return [
            ...self::listingCard($listing),
            'objectives' => $listing->objectives,
            'outcomes' => $listing->outcomes,
            'documents' => self::documents($listing->organization, MarketplaceDocument::LISTING, $listing->id),
        ];
    }

    /**
     * The latest version of a document shown with a published profile or
     * listing, for download; null for anything else.
     */
    public static function downloadable(string $documentId): ?DocumentVersion
    {
        $link = MarketplaceDocument::acrossOrganizations()->where('document_id', $documentId)->get()
            ->first(fn (MarketplaceDocument $link) => $link->subject_type === MarketplaceDocument::PROFILE
                ? OrganizationProfile::visible()->whereKey($link->subject_id)->exists()
                : FundingListing::visible()->whereKey($link->subject_id)->exists());
        $organization = $link === null ? null : Organization::query()->find($link->organization_id);
        if ($organization === null) {
            return null;
        }

        return app(CurrentOrganization::class)->run($organization, function () use ($documentId) {
            $document = Document::query()->openToAll()->find($documentId);

            return $document?->versions()->where('version', $document->current_version)->first();
        });
    }

    /**
     * The organization behind a published profile, to invite it; null otherwise.
     */
    public static function visibleOrganization(string $slug): ?Organization
    {
        return Organization::query()->where('slug', $slug)
            ->whereIn('id', OrganizationProfile::visible()->select('organization_id'))->first();
    }

    /**
     * A published profile's logo; null when there is none to show.
     */
    public static function logoPath(string $slug): ?string
    {
        return OrganizationProfile::visible()->whereHas('organization', fn ($query) => $query->where('slug', $slug))->value('logo_path');
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(OrganizationProfile $profile): array
    {
        return [
            'slug' => $profile->organization->slug,
            'name' => $profile->organization->name,
            'summary' => $profile->summary,
            'countryCode' => $profile->country_code,
            'location' => $profile->location,
            'sectors' => self::sectorNames($profile->sectors ?? []),
            'hasLogo' => $profile->logo_path !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listingCard(FundingListing $listing): array
    {
        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'summary' => $listing->summary,
            'organization' => ['slug' => $listing->organization->slug, 'name' => $listing->organization->name],
            'sector' => $listing->sector === null ? null : (Marketplace::SECTORS[$listing->sector] ?? $listing->sector),
            'countryCode' => $listing->country_code,
            'location' => $listing->location,
            'startOn' => $listing->start_on?->toDateString(),
            'endOn' => $listing->end_on?->toDateString(),
            'currency' => $listing->currency,
            'targetMinor' => $listing->target_minor,
            'confirmedMinor' => min($listing->confirmed_minor, $listing->target_minor),
            'remainingMinor' => $listing->remainingMinor(),
            'fundingStatus' => FundingListing::FUNDING_STATUSES[$listing->funding_status] ?? $listing->funding_status,
        ];
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private static function documents(Organization $organization, string $type, string $subjectId): array
    {
        return array_values(app(CurrentOrganization::class)->run($organization, fn () => MarketplaceDocument::query()
            ->where('subject_type', $type)->where('subject_id', $subjectId)
            ->whereHas('document', fn ($query) => $query->openToAll()->where('current_version', '>', 0))
            ->with('document:id,title')->get()
            ->map(fn (MarketplaceDocument $link) => ['id' => $link->document_id, 'title' => $link->document->title])
            ->sortBy('title')->all()));
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function sectorNames(array $keys): array
    {
        return array_map(fn (string $key) => Marketplace::SECTORS[$key] ?? $key, $keys);
    }

    /**
     * Words in the given columns or the organization's name, case-insensitively.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     */
    private static function text(Builder $query, ?string $text, array $columns, string $relation): void
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        $like = '%'.addcslashes($text, '%_\\').'%';

        // whereLike: case-insensitive on every database (ilike on PostgreSQL).
        $query->where(function (Builder $where) use ($columns, $like, $relation) {
            foreach ($columns as $column) {
                $where->orWhereLike($column, $like);
            }
            $where->orWhereHas($relation, fn ($organization) => $organization->whereLike('name', $like));
        });
    }
}
