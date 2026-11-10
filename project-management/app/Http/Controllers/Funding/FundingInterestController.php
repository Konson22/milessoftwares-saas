<?php

namespace App\Http\Controllers\Funding;

use App\Actions\FundingInterests;
use App\Http\Controllers\Controller;
use App\Models\FundingInterest;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An organization's own side of expressions of interest: what donors said about
 * the projects it listed, and its answers (marketplace.publish). Accepting
 * creates no grant and no confirmed funding.
 */
class FundingInterestController extends Controller
{
    public function index(): Response
    {
        $interests = FundingInterest::query()->with(['donor:id,name', 'listing:id,title'])
            ->where('organization_id', app(CurrentOrganization::class)->require()->id)
            ->latest('submitted_at')->get();

        return Inertia::render('portal/marketplace/received-interests', [
            'interests' => $interests->map(fn (FundingInterest $interest) => [
                'id' => $interest->id,
                'donor' => $interest->donor->name,
                'listing' => ['id' => $interest->funding_listing_id, 'title' => $interest->listing->title],
                'message' => $interest->message,
                'contactName' => $interest->contact_name,
                'contactEmail' => $interest->contact_email,
                'status' => $interest->status,
                'statusText' => $interest->statusText(),
                'response' => $interest->response,
                'next' => FundingInterest::NEXT[$interest->status] ?? [],
                'submittedAt' => $interest->submitted_at->toIso8601String(),
                'respondedAt' => $interest->responded_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function respond(Request $request, string $interest): RedirectResponse
    {
        $found = FundingInterest::query()->where('organization_id', app(CurrentOrganization::class)->require()->id)
            ->whereKey($interest)->firstOrFail();
        $validated = $request->validate([
            'status' => ['required', Rule::in([FundingInterest::UNDER_REVIEW, FundingInterest::ACCEPTED, FundingInterest::DECLINED])],
            'response' => ['nullable', 'string', 'max:2000'],
        ]);

        $found = FundingInterests::respond($found, $validated['status'], $this->text($validated['response'] ?? null), $this->member($request));

        return back()->with('success', "Marked {$found->statusText()}. The donor has been told.");
    }
}
