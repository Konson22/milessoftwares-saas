<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Marketplace;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceDocument;
use App\Models\OrganizationProfile;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's public profile in the funding marketplace: what donors
 * may find, and nothing else. Draft until published; unpublishing takes it,
 * and the listings beside it, off the marketplace (marketplace.publish).
 */
class PublicProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = Marketplace::profile();
        $organization = app(CurrentOrganization::class)->require();

        return Inertia::render('portal/marketplace/profile', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'profile' => [
                'status' => $profile->exists ? $profile->status : OrganizationProfile::DRAFT,
                'summary' => $profile->summary,
                'description' => $profile->description,
                'mission' => $profile->mission,
                'vision' => $profile->vision,
                'country_code' => $profile->country_code ?? $organization->country_code,
                'location' => $profile->location,
                'operating_areas' => implode(', ', $profile->operating_areas ?? []),
                'sectors' => $profile->sectors ?? [],
                'registration_number' => $profile->registration_number,
                'registration_authority' => $profile->registration_authority,
                'website' => $profile->website,
                'contact_email' => $profile->contact_email,
                'contact_phone' => $profile->contact_phone,
                'achievements' => $profile->achievements,
                'hasLogo' => $profile->logo_path !== null,
                'publishedAt' => $profile->published_at?->toIso8601String(),
                'documents' => $profile->exists ? Marketplace::chosenDocuments(MarketplaceDocument::PROFILE, $profile->id) : [],
            ],
            'sectors' => self::sectorOptions(),
            'documents' => Marketplace::documentOptions(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['country_code' => mb_strtoupper(trim($request->string('country_code')->toString())) ?: null]);
        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'mission' => ['nullable', 'string', 'max:2000'],
            'vision' => ['nullable', 'string', 'max:2000'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'location' => ['nullable', 'string', 'max:255'],
            'operating_areas' => ['nullable', 'string', 'max:1000'],
            'sectors' => ['nullable', 'array', 'max:6'],
            'sectors.*' => [Rule::in(array_keys(Marketplace::SECTORS))],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'registration_authority' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'achievements' => ['nullable', 'string', 'max:10000'],
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['uuid'],
        ], ['country_code.regex' => 'Two letters, e.g. SS, KE.', 'website.url' => 'A full address, starting with https://.']);

        $areas = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['operating_areas'] ?? ''))), fn (string $area) => $area !== ''));
        $documents = array_values($validated['documents'] ?? []);
        unset($validated['documents']);

        $by = $this->member($request);
        $profile = Marketplace::saveProfile([
            ...$validated,
            'operating_areas' => $areas === [] ? null : array_slice($areas, 0, 20),
            'sectors' => array_values($validated['sectors'] ?? []) ?: null,
        ], $by);
        Marketplace::setDocuments(MarketplaceDocument::PROFILE, $profile->id, $documents, $by);

        return back()->with('success', $profile->isPublished() ? 'Saved. Donors see the change now.' : 'Saved as a draft.');
    }

    public function publish(Request $request): RedirectResponse
    {
        Marketplace::publishProfile($this->member($request));

        return back()->with('success', 'Your profile is in the funding marketplace.');
    }

    public function unpublish(Request $request): RedirectResponse
    {
        Marketplace::unpublishProfile($this->member($request));

        return back()->with('success', 'Your profile and its listings are off the marketplace. Nothing was deleted.');
    }

    public function logo(Request $request): RedirectResponse
    {
        // No SVG: it can carry script.
        $validated = $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp']]);
        Marketplace::setLogo($validated['logo'], app(CurrentOrganization::class)->require(), $this->member($request));

        return back()->with('success', 'Logo changed.');
    }

    /**
     * The logo, to the organization's own members, published or not.
     */
    public function showLogo(): StreamedResponse
    {
        $path = Marketplace::profile()->logo_path;
        abort_if($path === null, 404);

        return Storage::disk(Marketplace::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function sectorOptions(): array
    {
        return array_values(collect(Marketplace::SECTORS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->all());
    }
}
