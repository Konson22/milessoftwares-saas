<?php

namespace App\Http\Controllers\Funding;

use App\Actions\Beneficiaries;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Partnership;
use App\Support\Modules;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The donors an organization is connected to, and the invitations from donors
 * waiting for its answer (organization.manage). Connecting shares nothing by
 * itself: each grant chooses what its funder sees.
 */
class DonorConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $this->organization();
        $connected = Partnership::query()->with('donor:id,name')
            ->where('beneficiary_organization_id', $organization->id)->whereNotNull('accepted_at')->get();
        $pending = Partnership::query()->with(['donor:id,name', 'inviter:id,name'])->pendingFor($organization)->get();
        // Grants are recorded against the funder record each connection made.
        $grants = Grant::query()->whereIn('funder_id', $connected->pluck('funder_id')->filter())
            ->selectRaw('funder_id, count(*) as grants')->groupBy('funder_id')->pluck('grants', 'funder_id');
        $canRecord = $organization->hasModule(Modules::GRANTS) && $this->can($request, 'grants.manage');

        return Inertia::render('portal/marketplace/donor-connections', [
            'connected' => $connected->sortBy(fn (Partnership $partnership) => mb_strtolower($partnership->donor->name))->map(fn (Partnership $partnership) => [
                'id' => $partnership->id,
                'donor' => $partnership->donor->name,
                'label' => $partnership->labelText(),
                'since' => $partnership->accepted_at?->toDateString(),
                'grants' => (int) ($grants[$partnership->funder_id] ?? 0),
                'recordGrantUrl' => $canRecord && $partnership->funder_id !== null
                    ? route('grants.create', ['funder' => $partnership->funder_id], false)
                    : null,
            ])->values(),
            'invitations' => $pending->map(fn (Partnership $partnership) => [
                'id' => $partnership->id,
                'donor' => $partnership->donor->name,
                'label' => $partnership->labelText(),
                'invitedBy' => $partnership->inviter->name,
                'expiresOn' => $partnership->expires_at->toDateString(),
            ])->values(),
        ]);
    }

    public function accept(Request $request, string $partnership): RedirectResponse
    {
        $found = $this->pending($partnership);
        Beneficiaries::acceptDirect($found, $this->organization(), $this->member($request));

        return back()->with('success', "Connected to {$found->donor->name}. Nothing is shared until you choose to, grant by grant.");
    }

    public function decline(Request $request, string $partnership): RedirectResponse
    {
        $found = $this->pending($partnership);
        Beneficiaries::decline($found, $this->member($request));

        return back()->with('success', "Invitation from {$found->donor->name} declined.");
    }

    private function organization(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    private function pending(string $id): Partnership
    {
        return Partnership::query()->with('donor')->pendingFor($this->organization())->whereKey($id)->firstOrFail();
    }
}
