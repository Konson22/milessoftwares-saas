<?php

namespace App\Sites\Central\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Actions\RegistrationIntents;
use App\Modules\Subscriptions\Models\RegistrationIntent;
use App\Modules\Subscriptions\Support\IntentRefused;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * "Start free trial", "Get started" and "Choose plan" from the public site,
 * on the auth host. The choice becomes a registration intent (see
 * RegistrationIntents) whose token travels in the link; registering,
 * verifying and signing in come back to it; the person then chooses the
 * organization it is for and confirms it.
 */
class GetStartedController extends Controller
{
    private const CODE = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * /trial?app=retail (the public site's trial links), /trial?product=ngo,
     * /get-started?plan=ngo-standard&action=subscribe. Creates the intent
     * and opens it; grants nothing.
     */
    public function start(Request $request, RegistrationIntents $intents, MarketingCatalog $catalog): RedirectResponse
    {
        $action = $request->routeIs('trial') ? 'trial' : ($request->query('action') === 'subscribe' ? 'subscribe' : 'trial');

        [$type, $code] = match (true) {
            $request->filled('plan') => ['plan', $request->string('plan')->toString()],
            $request->filled('product') => ['product', $request->string('product')->toString()],
            default => ['application', $request->string('app')->toString()],
        };

        $created = preg_match(self::CODE, $code) === 1
            ? $intents->create($type, $code, $action, $this->source($request), $this->campaign($request))
            : null;

        if ($created === null) {
            // Nothing (or nothing valid) chosen: the product list to choose from.
            return $request->user() === null
                ? redirect()->away(PlatformHosts::url(PlatformHosts::www(), 'start'))
                : redirect()->route('workspaces.continue');
        }

        return redirect()->route('get-started.open', ['token' => $created[1]]);
    }

    /**
     * The link with the token. A guest sees what they chose and registers or
     * signs in; once signed in it is claimed for that account.
     */
    public function open(Request $request, string $token, RegistrationIntents $intents): Response|RedirectResponse
    {
        $intent = $intents->find($token);
        $user = $request->user();

        if ($intent === null) {
            return $this->render($request, null, 'missing');
        }

        if (! $user instanceof User) {
            if ($intent->status !== 'pending' || $intent->isExpired()) {
                return $this->render($request, $intent, 'expired');
            }

            // Back here after registering or signing in, from any tab.
            $intents->remember($request->session(), $token);
            $request->session()->put('url.intended', $request->fullUrl());

            return $this->render($request, $intent, 'guest');
        }

        $intents->claimRemembered($request->session(), $user);

        if (! $intents->claim($intent, $user)) {
            return $this->render($request, $intent, $intent->user_id === null ? 'expired' : 'other_account');
        }

        $selection = route('get-started.show', ['intent' => $intent->id]);

        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('url.intended', $selection);

            return redirect()->route('verification.notice');
        }

        return redirect()->to($selection);
    }

    public function show(Request $request, RegistrationIntent $intent, RegistrationIntents $intents): Response
    {
        abort_unless($intent->user_id === $request->user()?->getAuthIdentifier(), 404);

        $state = match (true) {
            $intent->status === 'completed' => 'completed',
            $intent->status === 'cancelled', $intent->isExpired() => 'expired',
            $intents->describe($intent) === null => 'unavailable',
            default => 'choose',
        };

        return $this->render($request, $intent, $state);
    }

    public function store(Request $request, RegistrationIntent $intent, RegistrationIntents $intents, MembershipLookup $memberships): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($intent->user_id === $user->id, 404);

        $data = $request->validate(['organization_id' => ['required', 'uuid']]);
        $membership = $memberships->activeIn($user, $data['organization_id']);

        if ($membership === null) {
            throw ValidationException::withMessages(['organization_id' => __('Choose one of your organizations.')]);
        }

        return $this->completeFor($intent, $user, $membership->organization, $intents);
    }

    public function cancel(Request $request, RegistrationIntent $intent, RegistrationIntents $intents): RedirectResponse
    {
        abort_unless($intent->user_id === $request->user()?->getAuthIdentifier(), 404);
        $intents->cancel($intent, $request->user());

        return redirect()->route('workspaces');
    }

    /**
     * Completes the intent for the organization and opens the product there.
     * Shared with creating an organization from the intent.
     */
    public function completeFor(RegistrationIntent $intent, User $user, Organization $organization, RegistrationIntents $intents): SymfonyResponse
    {
        try {
            $intent = $intents->complete($intent, $user, $organization);
        } catch (IntentRefused $refused) {
            return redirect()->route('get-started.show', ['intent' => $intent->id])
                ->withErrors(['organization_id' => $refused->getMessage()]);
        } catch (Throwable $e) {
            // The trial may have started before something downstream failed;
            // a retry finds it running and finishes.
            Log::error('Registration intent could not be completed.', ['intent' => $intent->id, 'exception' => $e]);

            return redirect()->route('get-started.show', ['intent' => $intent->id])
                ->withErrors(['organization_id' => __('Something went wrong on our side. Try again in a moment; nothing will be started twice.')]);
        }

        return Inertia::location((string) RegistrationIntents::destination($intent->load('organization.primarySlug')));
    }

    private function render(Request $request, ?RegistrationIntent $intent, string $state): Response
    {
        $intents = app(RegistrationIntents::class);
        $user = $request->user();
        $target = $intent === null ? null : $intents->describe($intent);
        $own = $state === 'choose' && $user instanceof User
            ? app(MembershipLookup::class)->activeFor($user)->load(['organization.primarySlug', 'organization'])
            : collect();
        $owners = OrganizationMembership::acrossTenants()->with('user:id,name')
            ->whereIn('id', $own->pluck('organization.owner_membership_id')->filter()->all())->get()->keyBy('id');

        // Guests see it in the sign-in chrome; accounts in their own.
        return Inertia::render($user instanceof User ? 'central/get-started' : 'central/auth/get-started', [
            'state' => $state,
            'selection' => $intent === null ? null : [
                'id' => $intent->id,
                'kind' => $intent->target_type,
                'action' => $intent->action,
                'name' => $target['name'] ?? null,
                'summary' => $target['summary'] ?? null,
                'applications' => array_map(fn ($application) => $application->name, $target['applications'] ?? []),
                'plan' => ($plan = $target['plan'] ?? null) === null ? null : [
                    'name' => $plan->name,
                    'price' => $plan->price_minor === 0 ? 'Free' : $plan->currency.' '.number_format($plan->price_minor / 100, 2),
                    'interval' => $plan->billing_interval,
                    'trialDays' => $plan->trial_days,
                ],
                'expiresAt' => $intent->expires_at->toIso8601String(),
            ],
            'trialDays' => (int) config('platform.marketing.trial_days'),
            'organizations' => $own->map(fn (OrganizationMembership $membership) => [
                'id' => $membership->organization_id,
                'name' => $membership->organization->display_name,
                'address' => $membership->organization->primarySlug === null ? null : PlatformHosts::workspace($membership->organization->primarySlug->slug),
                'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                'open' => $membership->organization->status->resolvesWorkspace(),
                'ownerName' => $owners->get((string) $membership->organization->owner_membership_id)?->user?->name,
            ])->values()->all(),
            'createUrl' => $state === 'choose' && $intent !== null ? route('organizations.create', ['intent' => $intent->id, 'new' => 1]) : null,
            'completed' => $state === 'completed' && $intent !== null ? [
                'organization' => $intent->organization?->display_name,
                'outcome' => $intent->outcome,
                'url' => RegistrationIntents::destination($intent->load('organization.primarySlug')),
            ] : null,
            'others' => $user instanceof User ? RegistrationIntent::query()->open()->where('user_id', $user->id)
                ->when($intent !== null, fn ($query) => $query->whereKeyNot($intent->id))
                ->latest()->limit(5)->get()
                ->map(fn (RegistrationIntent $other) => ['name' => $intents->describe($other)['name'] ?? null, 'url' => route('get-started.show', ['intent' => $other->id])])
                ->filter(fn (array $other) => $other['name'] !== null)->values()->all() : [],
            'registerUrl' => route('register'),
            'loginUrl' => route('login'),
            'chooseAgainUrl' => PlatformHosts::url(PlatformHosts::www(), 'start'),
        ]);
    }

    private function source(Request $request): ?string
    {
        $from = trim($request->string('from')->toString(), '/');

        return preg_match('/^[a-z0-9-]+(\/[a-z0-9-]+){0,4}$/', $from) === 1 ? mb_substr($from, 0, 120) : null;
    }

    /**
     * @return array<string, string>
     */
    private function campaign(Request $request): array
    {
        $campaign = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = $request->string($key)->toString();
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $value) === 1) {
                $campaign[$key] = $value;
            }
        }

        return $campaign;
    }
}
