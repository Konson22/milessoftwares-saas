<?php

namespace App\Http\Controllers\Funding;

use App\Actions\DonorPortal;
use App\Audit\Audit;
use App\Enums\GrantReportStatus;
use App\Http\Controllers\Controller;
use App\Models\FunderContact;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use App\Support\DonorProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The portal as a funder's contact sees it. Everyone else gets 404, staff and
 * owners included, and so does a grant of another funder, or one not shared.
 */
class DonorPortalController extends Controller
{
    public function index(Request $request): Response
    {
        $contact = $this->contact($request);

        return Inertia::render('portal/donor/index', [
            'funder' => $contact->funder->name,
            'grants' => array_map(fn (array $grant) => [...$grant, 'url' => route('donor.grants.show', ['grant' => $grant['id']], false)], DonorProjection::grants($contact->funder_id)),
        ]);
    }

    public function show(Request $request, Grant $grant): Response
    {
        $this->shared($request, $grant);

        return Inertia::render('portal/donor/grant', [
            ...DonorProjection::grant($grant),
            'base' => route('donor.grants.show', ['grant' => $grant->id], false),
            'back' => route('donor.index', [], false),
        ]);
    }

    public function decide(Request $request, Grant $grant, GrantReport $report): RedirectResponse
    {
        $this->shared($request, $grant);
        $validated = $request->validate([
            'status' => ['required', Rule::in([GrantReportStatus::Accepted->value, GrantReportStatus::RevisionRequested->value])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DonorPortal::funderDecides($report, GrantReportStatus::from($validated['status']), $validated['reason'] ?? null, $this->member($request));

        return back()->with('success', $validated['status'] === GrantReportStatus::Accepted->value ? 'Report accepted.' : 'Revision requested.');
    }

    public function post(Request $request, Grant $grant): RedirectResponse
    {
        $this->shared($request, $grant);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        DonorPortal::post($grant, $validated['body'], $this->member($request), fromFunder: true);

        return back()->with('success', 'Message sent.');
    }

    public function document(Request $request, Grant $grant, GrantReport $report, GrantReportDocument $document): StreamedResponse
    {
        $this->shared($request, $grant);
        abort_unless(DonorProjection::documentIsDelivered($report), 404);
        Audit::record('grant.report_document_downloaded', $report, ['document' => $document->original_name], actor: $this->member($request));

        return Storage::disk(DonorPortal::DISK)->download($document->path, $document->original_name);
    }

    private function contact(Request $request): FunderContact
    {
        $contact = FunderContact::forUser($this->member($request));
        abort_if($contact === null, 404);

        return $contact;
    }

    private function shared(Request $request, Grant $grant): void
    {
        abort_unless(DonorProjection::canSee($this->contact($request)->funder_id, $grant), 404);
    }
}
