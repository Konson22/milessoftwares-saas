<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\Document;
use App\Models\FundingListing;
use App\Models\MarketplaceDocument;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * An organization's side of the funding marketplace: its public profile, the
 * projects it lists as seeking funding, and the documents it shows with them.
 * Runs inside the organization (its tenant scope). Nothing here copies or opens
 * a private record: each field is typed for the public, and a listing only
 * points at its project.
 */
final class Marketplace
{
    /** Where logos are kept: the private disk, served by MarketplaceController. */
    public const DISK = 'local';

    /** The sectors donors filter by. */
    public const SECTORS = [
        'agriculture' => 'Agriculture and food security',
        'climate' => 'Climate and environment',
        'education' => 'Education',
        'governance' => 'Governance and civil society',
        'health' => 'Health',
        'humanitarian' => 'Humanitarian response',
        'livelihoods' => 'Livelihoods and economic development',
        'nutrition' => 'Nutrition',
        'peacebuilding' => 'Peacebuilding',
        'protection' => 'Protection and gender',
        'shelter' => 'Shelter and infrastructure',
        'wash' => 'Water, sanitation and hygiene',
        'other' => 'Other',
    ];

    /** One profile per organization, made on first edit. */
    public static function profile(): OrganizationProfile
    {
        return OrganizationProfile::query()->firstOrNew([], ['status' => OrganizationProfile::DRAFT]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function saveProfile(array $details, User $by): OrganizationProfile
    {
        $profile = self::profile();
        $profile->fill($details);
        $profile->forceFill(['updated_by' => $by->id, 'status' => $profile->status ?? OrganizationProfile::DRAFT])->save();
        Audit::record('marketplace.profile_saved', $profile, [], actor: $by);

        return $profile;
    }

    /**
     * @throws ValidationException
     */
    public static function publishProfile(User $by): OrganizationProfile
    {
        $profile = self::profile();
        $missing = array_keys(array_filter([
            'summary' => blank($profile->summary),
            'description' => blank($profile->description),
            'country_code' => blank($profile->country_code),
            'sectors' => blank($profile->sectors),
        ]));
        if ($missing !== []) {
            throw ValidationException::withMessages(['publish' => 'Fill in a summary, description, country and at least one sector before publishing.']);
        }

        $profile->forceFill(['status' => OrganizationProfile::PUBLISHED, 'published_at' => $profile->published_at ?? now(), 'updated_by' => $by->id])->save();
        Audit::record('marketplace.profile_published', $profile, [], actor: $by);

        return $profile;
    }

    /**
     * Off the marketplace, and its listings with it (they show only beside a
     * published profile). Nothing is deleted.
     */
    public static function unpublishProfile(User $by): void
    {
        $profile = self::profile();
        if (! $profile->exists || ! $profile->isPublished()) {
            return;
        }
        $profile->forceFill(['status' => OrganizationProfile::UNPUBLISHED, 'updated_by' => $by->id])->save();
        Audit::record('marketplace.profile_unpublished', $profile, [], actor: $by);
    }

    public static function setLogo(UploadedFile $file, Organization $organization, User $by): void
    {
        $profile = self::profile();
        if (! $profile->exists) {
            $profile->forceFill(['status' => OrganizationProfile::DRAFT, 'updated_by' => $by->id])->save();
        }

        $old = $profile->logo_path;
        $path = $file->store("organizations/{$organization->id}/profile", self::DISK);
        $profile->forceFill(['logo_path' => $path, 'updated_by' => $by->id])->save();
        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
        Audit::record('marketplace.logo_changed', $profile, [], actor: $by);
    }

    /**
     * Lists one of the organization's projects, as a draft prefilled from it.
     * A project is listed once; listing it again opens the same listing.
     */
    public static function listProject(Project $project, User $by): FundingListing
    {
        $existing = FundingListing::query()->where('project_id', $project->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $listing = new FundingListing([
            'title' => $project->name,
            'summary' => $project->description,
            'start_on' => $project->start_on,
            'end_on' => $project->due_on,
            'currency' => $project->currency,
            'funding_status' => FundingListing::SEEKING,
        ]);
        $listing->forceFill([
            'project_id' => $project->id,
            'publication_status' => OrganizationProfile::DRAFT,
            'created_by' => $by->id,
        ])->save();
        Audit::record('marketplace.listing_created', $listing, ['project' => $project->code], actor: $by);

        return $listing;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function saveListing(FundingListing $listing, array $details, User $by): void
    {
        $listing->fill($details)->save();
        Audit::record('marketplace.listing_saved', $listing, [], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function publishListing(FundingListing $listing, User $by): void
    {
        if (! self::profile()->isPublished()) {
            throw ValidationException::withMessages(['publish' => 'Publish your organization profile first: listings show beside it.']);
        }
        if (blank($listing->summary) || blank($listing->sector) || $listing->target_minor <= 0) {
            throw ValidationException::withMessages(['publish' => 'Give the listing a description, a sector and a funding target before publishing.']);
        }

        $listing->forceFill(['publication_status' => OrganizationProfile::PUBLISHED, 'published_at' => $listing->published_at ?? now()])->save();
        Audit::record('marketplace.listing_published', $listing, [], actor: $by);
    }

    public static function unpublishListing(FundingListing $listing, User $by): void
    {
        if (! $listing->isPublished()) {
            return;
        }
        $listing->forceFill(['publication_status' => OrganizationProfile::UNPUBLISHED])->save();
        Audit::record('marketplace.listing_unpublished', $listing, [], actor: $by);
    }

    /**
     * The documents shown with a profile or listing: replaced as a set. Only
     * the organization's own, neither confidential nor archived.
     *
     * @param  list<string>  $documentIds
     *
     * @throws ValidationException
     */
    public static function setDocuments(string $subjectType, string $subjectId, array $documentIds, User $by): void
    {
        $documentIds = array_values(array_unique($documentIds));
        $allowed = Document::query()->openToAll()->whereIn('id', $documentIds)->pluck('id')->all();
        if (count($allowed) !== count($documentIds)) {
            throw ValidationException::withMessages(['documents' => 'Choose documents that are neither confidential nor archived.']);
        }

        DB::transaction(function () use ($subjectType, $subjectId, $allowed, $by) {
            MarketplaceDocument::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNotIn('document_id', $allowed)->delete();
            foreach ($allowed as $documentId) {
                MarketplaceDocument::query()->firstOrCreate(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'document_id' => $documentId]);
            }
            Audit::record('marketplace.documents_changed', null, ['subject' => $subjectType, 'documents' => count($allowed)], actor: $by);
        });
    }

    /**
     * The documents an organization may choose from: any open to all members.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function documentOptions(): array
    {
        return array_values(Document::query()->openToAll()->where('current_version', '>', 0)->orderBy('title')->get(['id', 'title'])
            ->map(fn (Document $document) => ['value' => $document->id, 'label' => $document->title])->all());
    }

    /**
     * @return list<string>
     */
    public static function chosenDocuments(string $subjectType, string $subjectId): array
    {
        return array_values(MarketplaceDocument::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->get(['document_id'])
            ->map(fn (MarketplaceDocument $link) => $link->document_id)->all());
    }
}
