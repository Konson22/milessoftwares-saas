<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Beneficiaries;
use App\Actions\DonorPortal;
use App\Audit\Audit;
use App\Enums\GrantReportStatus;
use App\Http\Controllers\Concerns\ValidatesOrganizationDetails;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\GrantReportDocument;
use App\Models\Organization;
use App\Models\Partnership;
use App\Support\DonorProjection;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The beneficiaries an organization that funds others works with, and the
 * grants they share with it. The request is the donor's, so {grant} and the rest
 * are not route-bound: each is read inside the beneficiary, after checking this
 * donor is connected to it, and only through App\Support\DonorProjection.
 */
class BeneficiaryController extends Controller
{
    use ValidatesOrganizationDetails;

    /** Who a donor usually invites to run what it funds. */
    public function index(): Response
    {
        $partnerships = Partnership::query()->with('beneficiary')
            ->where('donor_organization_id', $this->donor()->id)
            ->orderBy('name')->get();

        return Inertia::render('portal/beneficiaries/index', [
            'partnerships' => $partnerships->map(fn (Partnership $partnership) => [
                'id' => $partnership->id,
                'name' => $partnership->name,
                'label' => $partnership->labelText(),
                'email' => $partnership->isAccepted() ? null : $partnership->email,
                'status' => match (true) {
                    $partnership->isAccepted() => 'connected',
                    $partnership->isDeclined() => 'declined',
                    $partnership->expires_at->isPast() => 'expired',
                    default => 'invited',
                },
                'direct' => $partnership->invited_organization_id !== null,
                'expiresOn' => $partnership->isAccepted() ? null : $partnership->expires_at->toDateString(),
                'grants' => $partnership->isAccepted() && $partnership->beneficiary?->isActive()
                    ? count($this->inBeneficiary($partnership, fn () => DonorProjection::grants((string) $partnership->funder_id)))
                    : 0,
            ])->values(),
            'labels' => collect(Partnership::LABELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'timezones' => timezone_identifiers_list(),
            'defaults' => ['currency' => $this->donor()->currency, 'timezone' => $this->donor()->timezone],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->organizationDetails($request, [
            'label' => ['required', Rule::in(array_keys(Partnership::LABELS))],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Beneficiaries::invite($this->donor(), $this->member($request), [
            'label' => $validated['label'],
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'currency' => $validated['currency'],
            'timezone' => $validated['timezone'],
            'country_code' => $validated['country_code'] ?? null,
        ]);

        return back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function resend(Request $request, string $partnership): RedirectResponse
    {
        Beneficiaries::resend($this->pending($partnership), $this->member($request));

        return back()->with('success', 'Invitation sent again.');
    }

    public function destroy(Request $request, string $partnership): RedirectResponse
    {
        Beneficiaries::revoke($this->pending($partnership), $this->member($request));

        return back()->with('success', 'Invitation withdrawn.');
    }

    public function show(string $partnership): Response
    {
        $partnership = $this->connected($partnership);
        $grants = $this->inBeneficiary($partnership, fn () => DonorProjection::grants((string) $partnership->funder_id));

        return Inertia::render('portal/donor/index', [
            'funder' => $this->donor()->name,
            'beneficiary' => ['name' => $partnership->name, 'label' => $partnership->labelText(), 'back' => route('beneficiaries.index', [], false)],
            'grants' => array_map(fn (array $grant) => [
                ...$grant,
                'url' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant['id']], false),
            ], $grants),
        ]);
    }

    public function grant(string $partnership, string $grant): Response
    {
        $partnership = $this->connected($partnership);
        $payload = $this->inBeneficiary($partnership, fn () => DonorProjection::grant($this->sharedGrant($partnership, $grant)));

        return Inertia::render('portal/donor/grant', [
            ...$payload,
            'base' => route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant], false),
            'back' => route('beneficiaries.show', ['partnership' => $partnership->id], false),
        ]);
    }

    public function decide(Request $request, string $partnership, string $grant, string $report): RedirectResponse
    {
        $partnership = $this->connected($partnership);
        $validated = $request->validate([
            'status' => ['required', Rule::in([GrantReportStatus::Accepted->value, GrantReportStatus::RevisionRequested->value])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inBeneficiary($partnership, function () use ($request, $partnership, $grant, $report, $validated) {
            $shared = $this->sharedGrant($partnership, $grant);
            DonorPortal::funderDecides($this->report($shared, $report), GrantReportStatus::from($validated['status']), $validated['reason'] ?? null, $this->member($request));
        });

        return back()->with('success', $validated['status'] === GrantReportStatus::Accepted->value ? 'Report accepted.' : 'Revision requested.');
    }

    public function post(Request $request, string $partnership, string $grant): RedirectResponse
    {
        $partnership = $this->connected($partnership);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->inBeneficiary($partnership, fn () => DonorPortal::post($this->sharedGrant($partnership, $grant), $validated['body'], $this->member($request), fromFunder: true));

        return back()->with('success', 'Message sent.');
    }

    public function document(Request $request, string $partnership, string $grant, string $report, string $document): StreamedResponse
    {
        $partnership = $this->connected($partnership);

        $file = $this->inBeneficiary($partnership, function () use ($request, $partnership, $grant, $report, $document) {
            $shared = $this->report($this->sharedGrant($partnership, $grant), $report);
            abort_unless(DonorProjection::documentIsDelivered($shared), 404);
            $file = GrantReportDocument::query()->where('grant_report_id', $shared->id)->findOrFail($document);
            Audit::record('grant.report_document_downloaded', $shared, ['document' => $file->original_name], actor: $this->member($request));

            return $file;
        });

        return Storage::disk(DonorPortal::DISK)->download($file->path, $file->original_name);
    }

    private function donor(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    private function pending(string $id): Partnership
    {
        return Partnership::query()->where('donor_organization_id', $this->donor()->id)->whereNull('accepted_at')->findOrFail($id);
    }

    private function connected(string $id): Partnership
    {
        $partnership = Partnership::query()->with('beneficiary')->where('donor_organization_id', $this->donor()->id)->whereNotNull('accepted_at')->findOrFail($id);
        abort_unless($partnership->beneficiary?->isActive(), 404);

        return $partnership;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inBeneficiary(Partnership $partnership, callable $callback): mixed
    {
        $beneficiary = $partnership->beneficiary;
        abort_if($beneficiary === null, 404);

        return app(CurrentOrganization::class)->run($beneficiary, $callback);
    }

    private function sharedGrant(Partnership $partnership, string $id): Grant
    {
        $grant = Grant::query()->findOrFail($id);
        abort_unless(DonorProjection::canSee((string) $partnership->funder_id, $grant), 404);

        return $grant;
    }

    private function report(Grant $grant, string $id): GrantReport
    {
        return GrantReport::query()->where('grant_id', $grant->id)->findOrFail($id);
    }
}
