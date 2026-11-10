<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Marketplace;
use App\Enums\GrantStatus;
use App\Http\Controllers\Controller;
use App\Models\FundingListing;
use App\Models\GrantAllocation;
use App\Models\MarketplaceDocument;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects the organization lists as seeking funding. A listing points at an
 * existing project and is its public face: nothing of the project becomes
 * public by itself, and the target is what the organization asks for, not its
 * approved budget (marketplace.publish).
 */
class FundingListingController extends Controller
{
    public function index(): Response
    {
        $listings = FundingListing::query()->with('project:id,code,name')->orderBy('title')->get();

        return Inertia::render('portal/marketplace/listings', [
            'listings' => $listings->map(fn (FundingListing $listing) => [
                'id' => $listing->id,
                'title' => $listing->title,
                'project' => $listing->project->code.' · '.$listing->project->name,
                'publication' => $listing->publication_status,
                'funding' => FundingListing::FUNDING_STATUSES[$listing->funding_status] ?? $listing->funding_status,
                'currency' => $listing->currency,
                'targetMinor' => $listing->target_minor,
                'remainingMinor' => $listing->remainingMinor(),
            ])->values(),
            'projects' => Project::query()->whereIn('status', Project::OPEN)
                ->whereNotIn('id', FundingListing::query()->select('project_id'))
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"])->values(),
            'profilePublished' => Marketplace::profile()->isPublished(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['project_id' => ['required', 'uuid']]);
        // The organization's own project only (tenant scope).
        $project = Project::query()->whereKey((string) $validated['project_id'])->firstOrFail();
        $listing = Marketplace::listProject($project, $this->member($request));

        return to_route('funding-listings.edit', $listing)->with('success', 'Draft listing made from the project. Nothing is public yet.');
    }

    public function edit(FundingListing $listing): Response
    {
        $listing->load('project:id,code,name,budget_minor,currency');

        return Inertia::render('portal/marketplace/listing', [
            'listing' => [
                'id' => $listing->id,
                'project' => $listing->project->code.' · '.$listing->project->name,
                'title' => $listing->title,
                'summary' => $listing->summary,
                'sector' => $listing->sector,
                'country_code' => $listing->country_code,
                'location' => $listing->location,
                'objectives' => $listing->objectives,
                'outcomes' => $listing->outcomes,
                'start_on' => $listing->start_on?->toDateString(),
                'end_on' => $listing->end_on?->toDateString(),
                'currency' => $listing->currency,
                'target' => Money::toMajor($listing->target_minor, $listing->currency),
                'confirmed' => Money::toMajor($listing->confirmed_minor, $listing->currency),
                'remainingMinor' => $listing->remainingMinor(),
                'funding_status' => $listing->funding_status,
                'publication' => $listing->publication_status,
                'documents' => Marketplace::chosenDocuments(MarketplaceDocument::LISTING, $listing->id),
            ],
            // For the organization only, never shown in the marketplace: what
            // its own grants already put into this project.
            'internal' => [
                'budgetMinor' => $listing->project->budget_minor,
                'budgetCurrency' => $listing->project->currency,
                'awardedMinor' => $this->awardedToProject($listing->project),
            ],
            'sectors' => PublicProfileController::sectorOptions(),
            'fundingStatuses' => collect(FundingListing::FUNDING_STATUSES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'documents' => Marketplace::documentOptions(),
            'profilePublished' => Marketplace::profile()->isPublished(),
        ]);
    }

    public function update(Request $request, FundingListing $listing): RedirectResponse
    {
        $request->merge([
            'country_code' => mb_strtoupper(trim($request->string('country_code')->toString())) ?: null,
            'currency' => mb_strtoupper(trim($request->string('currency')->toString())),
        ]);
        $currency = $request->string('currency')->toString();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'sector' => ['nullable', Rule::in(array_keys(Marketplace::SECTORS))],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'location' => ['nullable', 'string', 'max:255'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'outcomes' => ['nullable', 'string', 'max:5000'],
            'start_on' => ['nullable', 'date'],
            'end_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'target' => ['required', 'string', Money::rule($currency)],
            'confirmed' => ['nullable', 'string', Money::rule($currency)],
            'funding_status' => ['required', Rule::in(array_keys(FundingListing::FUNDING_STATUSES))],
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['uuid'],
        ], ['currency.regex' => 'A three-letter currency code, e.g. USD.', 'country_code.regex' => 'Two letters, e.g. SS, KE.']);

        $target = Money::toMinor($validated['target'], $currency);
        $confirmed = Money::toMinor($validated['confirmed'] ?? '0', $currency);
        if ($confirmed > $target) {
            return back()->withErrors(['confirmed' => 'Confirmed funding cannot be more than the target.']);
        }

        $by = $this->member($request);
        $documents = array_values($validated['documents'] ?? []);
        unset($validated['documents'], $validated['target'], $validated['confirmed']);
        Marketplace::saveListing($listing, [...$validated, 'target_minor' => $target, 'confirmed_minor' => $confirmed], $by);
        Marketplace::setDocuments(MarketplaceDocument::LISTING, $listing->id, $documents, $by);

        return back()->with('success', $listing->isPublished() ? 'Saved. Donors see the change now.' : 'Saved as a draft.');
    }

    public function publish(Request $request, FundingListing $listing): RedirectResponse
    {
        Marketplace::publishListing($listing, $this->member($request));

        return back()->with('success', 'The listing is in the funding marketplace.');
    }

    public function unpublish(Request $request, FundingListing $listing): RedirectResponse
    {
        Marketplace::unpublishListing($listing, $this->member($request));

        return back()->with('success', 'The listing is off the marketplace. Nothing was deleted.');
    }

    /**
     * Awarded grants' allocations to the project, in its currency: a guide for
     * the confirmed amount the organization chooses to state.
     */
    private function awardedToProject(Project $project): int
    {
        return (int) GrantAllocation::query()->where('project_id', $project->id)
            ->whereHas('grant', fn ($query) => $query->whereIn('status', [GrantStatus::Active->value, GrantStatus::Closed->value]))
            ->sum('project_amount_minor');
    }
}
