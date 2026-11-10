<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Beneficiaries;
use App\Actions\FundingInterests;
use App\Actions\Marketplace;
use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\FundingInterest;
use App\Models\FundingListing;
use App\Models\Organization;
use App\Models\Partnership;
use App\Support\MarketplaceView;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The funding marketplace, for an organization that funds others: finding
 * organizations and the projects they list as seeking funding. Everything
 * shown comes through App\Support\MarketplaceView, which reads only what each
 * organization published (grants.view, and funding switched on).
 */
class MarketplaceController extends Controller
{
    public function organizations(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('portal/marketplace/organizations', [
            'organizations' => MarketplaceView::organizations($filters),
            'filters' => $filters,
            'sectors' => PublicProfileController::sectorOptions(),
        ]);
    }

    public function organization(Request $request, string $slug): Response
    {
        $organization = MarketplaceView::organization($slug);
        $model = MarketplaceView::visibleOrganization($slug);
        abort_if($organization === null || $model === null, 404);
        $own = $model->id === $this->donor()->id;

        return Inertia::render('portal/marketplace/organization', [
            'organization' => $organization,
            'connection' => $own ? null : $this->connectionWith($model->id),
            'canInvite' => ! $own && $this->can($request, 'organization.manage'),
        ]);
    }

    public function listings(Request $request): Response
    {
        $filters = [
            ...$this->filters($request),
            ...$request->validate([
                'status' => ['nullable', Rule::in(array_keys(FundingListing::FUNDING_STATUSES))],
                'currency' => ['nullable', 'string', 'size:3'],
            ]),
        ];

        return Inertia::render('portal/marketplace/projects', [
            'listings' => MarketplaceView::listings($filters),
            'filters' => [...$filters, 'status' => $filters['status'] ?? null, 'currency' => $filters['currency'] ?? null],
            'sectors' => PublicProfileController::sectorOptions(),
            'fundingStatuses' => collect(FundingListing::FUNDING_STATUSES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function listing(Request $request, string $listing): Response
    {
        $found = MarketplaceView::listing($listing);
        abort_if($found === null, 404);
        $user = $this->member($request);
        $own = FundingListing::visible()->whereKey($listing)->value('organization_id') === $this->donor()->id;
        $interest = FundingInterest::query()->with('listing:id,title')->where('donor_organization_id', $this->donor()->id)
            ->where('funding_listing_id', $listing)->latest('submitted_at')->first();

        return Inertia::render('portal/marketplace/project', [
            'listing' => $found,
            'interest' => $interest === null ? null : $this->interestRow($interest),
            'canExpress' => ! $own && $this->can($request, 'grants.manage')
                && ($interest === null || ! in_array($interest->status, FundingInterest::OPEN, true)),
            'contact' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function logo(string $slug): StreamedResponse
    {
        $path = MarketplaceView::logoPath($slug);
        abort_if($path === null, 404);

        return Storage::disk(Marketplace::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function document(Request $request, string $document): StreamedResponse
    {
        $version = MarketplaceView::downloadable($document);
        abort_if($version === null, 404);

        Audit::record('marketplace.document_downloaded', null, ['document_id' => $document], app(CurrentOrganization::class)->require()->id, $this->member($request));

        return Storage::disk('local')->download($version->path, $version->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Expresses interest in a listing. Not a grant, a commitment or confirmed
     * funding: the organization only reads it and answers.
     */
    public function expressInterest(Request $request, string $listing): RedirectResponse
    {
        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        FundingInterests::express($this->donor(), $listing, $this->member($request), $validated);

        return back()->with('success', 'Interest sent. They will reply here.');
    }

    /**
     * The interests this organization has expressed, and the answers.
     */
    public function interests(Request $request): Response
    {
        $interests = FundingInterest::query()->with(['organization:id,slug,name', 'listing:id,title', 'partnership'])
            ->where('donor_organization_id', $this->donor()->id)->latest('submitted_at')->get();

        return Inertia::render('portal/marketplace/interests', [
            'interests' => $interests->map(fn (FundingInterest $interest) => [
                ...$this->interestRow($interest),
                'organization' => ['slug' => $interest->organization->slug, 'name' => $interest->organization->name],
                'partnership' => $this->connectionStatus($interest->partnership),
                'canInvite' => $interest->status === FundingInterest::ACCEPTED
                    && $this->connectionStatus($interest->partnership) === null
                    && $this->can($request, 'organization.manage'),
            ])->values(),
        ]);
    }

    public function inviteFromInterest(Request $request, string $interest): RedirectResponse
    {
        $found = FundingInterest::query()->with('donor', 'organization')
            ->where('donor_organization_id', $this->donor()->id)->whereKey($interest)->firstOrFail();
        $partnership = FundingInterests::invite($found, $this->member($request));

        return back()->with('success', $partnership->isAccepted() ? "You are already connected to {$found->organization->name}." : "Invitation sent to {$found->organization->name}.");
    }

    /**
     * Invites an organization on the platform directly, from its profile.
     */
    public function invite(Request $request, string $slug): RedirectResponse
    {
        $organization = MarketplaceView::visibleOrganization($slug);
        abort_if($organization === null, 404);
        $validated = $request->validate(['label' => ['nullable', Rule::in(array_keys(Partnership::LABELS))]]);

        $partnership = Beneficiaries::inviteOrganization($this->donor(), $organization, $this->member($request), $validated['label'] ?? 'implementer');

        return back()->with('success', $partnership->isAccepted() ? "You are already connected to {$organization->name}." : "Invitation sent to {$organization->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function interestRow(FundingInterest $interest): array
    {
        return [
            'id' => $interest->id,
            'listing' => ['id' => $interest->funding_listing_id, 'title' => $interest->listing->title],
            'message' => $interest->message,
            'contactName' => $interest->contact_name,
            'contactEmail' => $interest->contact_email,
            'status' => $interest->status,
            'statusText' => $interest->statusText(),
            'response' => $interest->response,
            'submittedAt' => $interest->submitted_at->toIso8601String(),
            'respondedAt' => $interest->responded_at?->toIso8601String(),
        ];
    }

    /**
     * Where this donor stands with an organization: connected, invited, or
     * nothing (declined, expired or never invited).
     */
    private function connectionStatus(?Partnership $partnership): ?string
    {
        return match (true) {
            $partnership === null => null,
            $partnership->isAccepted() => 'connected',
            $partnership->isPending() => 'invited',
            default => null,
        };
    }

    private function connectionWith(string $organizationId): ?string
    {
        $partnership = Partnership::query()->where('donor_organization_id', $this->donor()->id)
            ->where(fn ($query) => $query->where('beneficiary_organization_id', $organizationId)->orWhere('invited_organization_id', $organizationId))
            ->get()->first(fn (Partnership $partnership) => $partnership->isAccepted() || $partnership->isPending());

        return $this->connectionStatus($partnership);
    }

    private function donor(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    /**
     * @return array{q: string|null, sector: string|null, country: string|null}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', Rule::in(array_keys(Marketplace::SECTORS))],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        return ['q' => $validated['q'] ?? null, 'sector' => $validated['sector'] ?? null, 'country' => $validated['country'] ?? null];
    }
}
