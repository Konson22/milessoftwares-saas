<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Organizations;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first thing an organization does in Projects: say whether it funds
 * others (funder or donor) or carries out projects (implementer). Its menu
 * follows. Someone who manages it chooses; Administration → Settings changes
 * it later.
 */
class AccountTypeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->require();
        if (! $organization->needsAccountType()) {
            return to_route('home');
        }

        return Inertia::render('portal/account-type', [
            'organization' => $organization->name,
            'canChoose' => $this->can($request, 'organization.manage'),
            // Funding already on (from before account types): suggested.
            'suggested' => $organization->isDonor() ? Organization::FUNDER : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['account_type' => ['required', Rule::in(Organization::ACCOUNT_TYPES)]]);
        $organization = app(CurrentOrganization::class)->require();

        Organizations::chooseAccountType($organization, $validated['account_type'], $this->member($request));

        return to_route('home')->with('success', $organization->isFunder()
            ? 'Welcome. Invite the organizations you fund from Beneficiaries.'
            : 'Welcome. Start with your first project.');
    }
}
