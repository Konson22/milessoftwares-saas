<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Marketing\Actions\SubmitLead;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Marketing\Support\SpamGuard;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demo request and contact forms. Spam is answered exactly like a real
 * submission, so the thank-you page tells a bot nothing.
 */
class LeadController extends Controller
{
    public function __construct(private readonly SubmitLead $leads) {}

    public function demoForm(Request $request, MarketingCatalog $catalog): Response
    {
        return Inertia::render('public/demo', [
            'products' => array_map(fn (array $product) => ['code' => $product['code'], 'name' => $product['name']], $catalog->products()),
            'selected' => $request->string('product')->toString() ?: null,
            'formToken' => SpamGuard::issueToken(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'demo'),
        ]);
    }

    public function storeDemo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->contactRules(),
            'product' => ['nullable', 'string', Rule::exists('applications', 'code')->whereNot('kind', ApplicationKind::Platform->value)],
            'preferred_contact_time' => ['nullable', 'string', 'max:255'],
        ]);

        $this->leads->demo($request, [
            ...$this->contactFields($validated),
            'application_id' => isset($validated['product']) ? Application::query()->where('code', $validated['product'])->value('id') : null,
            'preferred_contact_time' => $validated['preferred_contact_time'] ?? null,
        ]);

        return redirect()->route('marketing.thanks');
    }

    public function contactForm(): Response
    {
        return Inertia::render('public/contact', [
            'formToken' => SpamGuard::issueToken(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'contact'),
        ]);
    }

    public function storeContact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->contactRules(),
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $this->leads->contact($request, [...$this->contactFields($validated), 'subject' => $validated['subject'] ?? null]);

        return redirect()->route('marketing.thanks');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function contactRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'message' => ['nullable', 'string', 'max:5000'],
            SpamGuard::TOKEN_FIELD => ['required', 'string'],
            SpamGuard::HONEYPOT_FIELD => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name: string, email: string, company: string|null, phone: string|null, country_code: string|null, message: string|null}
     */
    private function contactFields(array $validated): array
    {
        return [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'company' => isset($validated['company']) ? (string) $validated['company'] : null,
            'phone' => isset($validated['phone']) ? (string) $validated['phone'] : null,
            'country_code' => isset($validated['country_code']) ? (string) $validated['country_code'] : null,
            'message' => isset($validated['message']) ? (string) $validated['message'] : null,
        ];
    }
}
