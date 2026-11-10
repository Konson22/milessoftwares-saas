<?php

namespace App\Http\Controllers\Funding;

use App\Actions\DonorPortal;
use App\Http\Controllers\Controller;
use App\Models\ExpenseDocument;
use App\Models\Funder;
use App\Models\FunderContact;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The donor portal as staff manage it, from the grant: what it shares, who
 * acts for the funder, report documents, and the conversation.
 */
class GrantPortalController extends Controller
{
    public function share(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate([
            'portal_enabled' => ['required', 'boolean'],
            'portal_budget' => ['required', 'boolean'],
            'portal_spending' => ['required', 'boolean'],
            'portal_progress' => ['required', 'boolean'],
            'portal_tasks' => ['sometimes', 'boolean'],
            'portal_milestones' => ['sometimes', 'boolean'],
            'portal_commitments' => ['sometimes', 'boolean'],
        ]);

        // A switch left out of the save is off: nothing is shared by default.
        DonorPortal::share($grant, [
            'portal_enabled' => (bool) $validated['portal_enabled'],
            'portal_budget' => (bool) $validated['portal_budget'],
            'portal_spending' => (bool) $validated['portal_spending'],
            'portal_commitments' => (bool) ($validated['portal_commitments'] ?? false),
            'portal_progress' => (bool) $validated['portal_progress'],
            'portal_milestones' => (bool) ($validated['portal_milestones'] ?? false),
            'portal_tasks' => (bool) ($validated['portal_tasks'] ?? false),
        ], $this->member($request));

        return back()->with('success', 'Sharing saved.');
    }

    public function invite(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $funder = Funder::query()->findOrFail($grant->funder_id);

        DonorPortal::invite($funder, $validated['email'], $this->member($request));

        return back()->with('success', 'Invitation sent.');
    }

    public function removeContact(Request $request, Grant $grant, FunderContact $contact): RedirectResponse
    {
        abort_unless($contact->funder_id === $grant->funder_id, 404);

        DonorPortal::removeContact($contact, $this->member($request));

        return back()->with('success', 'Their access has ended.');
    }

    public function post(Request $request, Grant $grant): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        DonorPortal::post($grant, $validated['body'], $this->member($request), fromFunder: false);

        return back()->with('success', 'Message sent.');
    }

    public function attach(Request $request, Grant $grant, GrantReport $report): RedirectResponse
    {
        $validated = $request->validate(['document' => ['required', ...ExpenseDocument::RULES]]);

        DonorPortal::attach($report, $validated['document'], $this->member($request));

        return back()->with('success', 'Document attached.');
    }

    public function detach(Request $request, Grant $grant, GrantReport $report, GrantReportDocument $document): RedirectResponse
    {
        DonorPortal::detach($report, $document, $this->member($request));

        return back()->with('success', 'Document removed.');
    }

    public function document(Grant $grant, GrantReport $report, GrantReportDocument $document): StreamedResponse
    {
        return Storage::disk(DonorPortal::DISK)->download($document->path, $document->original_name);
    }
}
