<?php

namespace App\Http\Controllers\Funding;

use App\Audit\Audit;
use App\Enums\FunderKind;
use App\Enums\GrantStatus;
use App\Http\Controllers\Controller;
use App\Models\Funder;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's funders: who they are and how to reach them.
 */
class FunderController extends Controller
{
    public function index(Request $request): Response
    {
        $funders = Funder::query()
            ->withCount(['grants', 'grants as active_grants_count' => fn ($query) => $query->where('status', GrantStatus::Active)])
            ->orderByDesc('active')->orderBy('name')->get();

        return Inertia::render('portal/funding/funders/index', [
            'funders' => $funders->map(fn (Funder $funder) => [
                'id' => $funder->id,
                'name' => $funder->name,
                'kind' => $funder->kind->label(),
                'countryCode' => $funder->country_code,
                'contactName' => $funder->contact_name,
                'contactEmail' => $funder->contact_email,
                'active' => $funder->active,
                'grants' => (int) $funder->getAttribute('grants_count'),
                'activeGrants' => (int) $funder->getAttribute('active_grants_count'),
            ]),
            'canManage' => $this->can($request, 'grants.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('portal/funding/funders/form', ['funder' => null, 'kinds' => FunderKind::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $funder = Funder::query()->create($this->details($request, null));
        Audit::record('funder.created', $funder, ['name' => $funder->name]);

        return to_route('funders.index')->with('success', "{$funder->name} added to your funders.");
    }

    public function edit(Funder $funder): Response
    {
        return Inertia::render('portal/funding/funders/form', [
            'funder' => [
                'id' => $funder->id,
                'name' => $funder->name,
                'kind' => $funder->kind->value,
                'countryCode' => $funder->country_code,
                'contactName' => $funder->contact_name,
                'contactEmail' => $funder->contact_email,
                'website' => $funder->website,
                'notes' => $funder->notes,
                'active' => $funder->active,
            ],
            'kinds' => FunderKind::options(),
        ]);
    }

    public function update(Request $request, Funder $funder): RedirectResponse
    {
        $details = $this->details($request, $funder);
        $details['active'] = $request->boolean('active');
        $funder->fill($details)->save();
        Audit::record('funder.updated', $funder, ['name' => $funder->name, 'active' => $funder->active]);

        return to_route('funders.index')->with('success', "{$funder->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request, ?Funder $funder): array
    {
        $request->merge(['country_code' => mb_strtoupper(trim($request->string('country_code')->toString())) ?: null]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('funders', 'name')->where('organization_id', app(CurrentOrganization::class)->id())->ignore($funder?->id)],
            'kind' => ['required', Rule::enum(FunderKind::class)],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['name.unique' => 'You already have a funder with this name.']);

        return [
            'name' => trim($validated['name']),
            'kind' => $validated['kind'],
            'country_code' => $validated['country_code'] ?? null,
            'contact_name' => $this->text($validated['contact_name'] ?? null),
            'contact_email' => $this->text($validated['contact_email'] ?? null),
            'website' => $this->text($validated['website'] ?? null),
            'notes' => $this->text($validated['notes'] ?? null),
        ];
    }
}
