<?php

namespace App\Modules\Subscriptions\Actions;

use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Support\TrialRefused;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\RegistrationIntent;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Support\IntentRefused;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registration intents: what a visitor chose on the public site (an
 * application's trial, an ERP product such as NGO, or a plan), kept in
 * Central while they register, verify their address, sign in and choose
 * the organization it is for.
 *
 * The visitor carries an opaque token (only its hash is stored). Signing in
 * claims the intent for that account; from then on only that account can
 * see or complete it. Completing it goes through the existing lifecycle
 * (ManageEntitlements::startTrial, PlanRequests::startTrial/requestPlan):
 * nothing here grants access on its own, and the codes are checked
 * against the catalog again at completion. Each intent stands alone, so
 * two tabs choosing two products never overwrite each other.
 */
final class RegistrationIntents
{
    public const LIFETIME_DAYS = 7;

    /** Tokens a guest opened in this browser, claimed once they sign in. */
    public const SESSION_KEY = 'registration_intents';

    private const REMEMBERED = 5;

    public function __construct(
        private readonly MarketingCatalog $catalog,
        private readonly ManageEntitlements $entitlements,
        private readonly PlanRequests $plans,
        private readonly MembershipLookup $memberships,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A new intent for a valid choice, with its token; null when the choice
     * is not something the catalog offers right now.
     *
     * @param  array<string, string>  $campaign
     * @return array{0: RegistrationIntent, 1: string}|null
     */
    public function create(string $type, string $code, string $action, ?string $source = null, array $campaign = []): ?array
    {
        if ($this->resolve($type, $code, $action) === null) {
            return null;
        }

        $token = Str::random(48);
        $intent = new RegistrationIntent;
        $intent->forceFill([
            'token_hash' => self::hash($token),
            'target_type' => $type,
            'target_code' => $code,
            'plan_code' => $type === 'plan' ? $code : null,
            'action' => $action,
            'source' => $source,
            'campaign' => $campaign === [] ? null : $campaign,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ])->save();

        $this->audit->record('registration_intent.created', $intent, [
            'target' => "{$type}:{$code}",
            'action' => $action,
            'source' => $source,
        ] + ($campaign === [] ? [] : ['campaign' => $campaign]));

        return [$intent, $token];
    }

    public function find(string $token): ?RegistrationIntent
    {
        return RegistrationIntent::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * Binds an unclaimed, open intent to the account now signed in. True
     * when the intent is (now) this account's.
     */
    public function claim(RegistrationIntent $intent, User $user): bool
    {
        if ($intent->user_id === null && $intent->status === 'pending' && ! $intent->isExpired()) {
            // Only one account can win a race for the same token.
            $won = RegistrationIntent::query()->whereKey($intent->id)->whereNull('user_id')
                ->update(['user_id' => $user->id, 'claimed_at' => now(), 'updated_at' => now()]);
            $intent->refresh();

            if ($won === 1) {
                $this->audit->record('registration_intent.claimed', $intent, ['target' => "{$intent->target_type}:{$intent->target_code}"], actor: $user);
            }
        }

        return $intent->user_id === $user->id;
    }

    public function remember(Session $session, string $token): void
    {
        /** @var list<string> $tokens */
        $tokens = array_values(array_filter((array) $session->get(self::SESSION_KEY, []), 'is_string'));
        $tokens = array_values(array_unique([...array_diff($tokens, [$token]), $token]));
        $session->put(self::SESSION_KEY, array_slice($tokens, -self::REMEMBERED));
    }

    /**
     * Claims what a guest chose in this browser before signing in.
     */
    public function claimRemembered(Session $session, User $user): void
    {
        foreach ((array) $session->pull(self::SESSION_KEY, []) as $token) {
            if (is_string($token) && ($intent = $this->find($token)) !== null) {
                $this->claim($intent, $user);
            }
        }
    }

    /** The most recent open intent of this account, to resume after signing in. */
    public function latestOpen(User $user): ?RegistrationIntent
    {
        return RegistrationIntent::query()->open()->where('user_id', $user->id)->latest('claimed_at')->latest('created_at')->first();
    }

    /**
     * What the intent stands for today, from the catalog; null when it is
     * no longer offered.
     *
     * @return array{name: string, summary: string|null, applications: list<Application>, plan: Plan|null}|null
     */
    public function describe(RegistrationIntent $intent): ?array
    {
        return $this->resolve($intent->target_type, $intent->target_code, $intent->action);
    }

    /**
     * Applies the intent to an organization the account owns, once.
     * Repeating it for the same organization returns the first result;
     * concurrent completions are serialized on the intent.
     *
     * @throws IntentRefused
     */
    public function complete(RegistrationIntent $intent, User $user, Organization $organization): RegistrationIntent
    {
        DB::select('SELECT pg_advisory_lock(hashtext(?))', ["registration-intent:{$intent->id}"]);

        try {
            $intent->refresh();

            if ($intent->status === 'completed') {
                if ($intent->organization_id === $organization->id && $intent->user_id === $user->id) {
                    return $intent;
                }

                throw new IntentRefused(IntentRefused::USED_ELSEWHERE, __('This selection has already been used for another organization. Choose the product again to add it here.'));
            }

            if ($intent->user_id !== $user->id) {
                throw new IntentRefused(IntentRefused::OTHER_ACCOUNT, __('This selection belongs to another account. Choose the product again while signed in as yourself.'));
            }

            if ($intent->status !== 'pending' || $intent->isExpired()) {
                throw new IntentRefused(IntentRefused::EXPIRED, __('This selection has expired. Choose the product again to continue.'));
            }

            $membership = $this->memberships->activeIn($user, $organization->id);
            if ($membership === null || $organization->owner_membership_id !== $membership->id) {
                throw new IntentRefused(IntentRefused::NOT_OWNER, __('Only the owner of :organization can add products to it. Ask them to start it, or create your own organization.', ['organization' => $organization->display_name]));
            }

            if (! $organization->status->resolvesWorkspace()) {
                throw new IntentRefused(IntentRefused::ORGANIZATION_CLOSED, __(':organization is not open, so nothing can be added to it.', ['organization' => $organization->display_name]));
            }

            $target = $this->describe($intent);
            if ($target === null) {
                throw new IntentRefused(IntentRefused::UNAVAILABLE, __('This is no longer available to start. Choose another product or plan.'));
            }

            [$outcome, $codes] = $target['plan'] !== null
                ? $this->activatePlan($organization, $target['plan'], $intent->action, $user)
                : $this->startTrials($organization, $target['applications'], $user);

            $destination = collect($target['applications'])->first(fn (Application $application) => $application->route_prefix !== null);
            $path = $destination === null ? '' : (string) $destination->route_prefix;

            $intent->forceFill([
                'organization_id' => $organization->id,
                'status' => 'completed',
                'outcome' => $outcome,
                'outcome_details' => [
                    'applications' => $codes,
                    'destination' => $outcome === 'requested' ? 'manage/subscription' : $path,
                ],
                'completed_at' => now(),
            ])->save();

            $this->audit->record('registration_intent.completed', $intent, [
                'target' => "{$intent->target_type}:{$intent->target_code}",
                'outcome' => $outcome,
                'applications' => $codes,
            ], actor: $user, organizationId: $organization->id);

            return $intent;
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', ["registration-intent:{$intent->id}"]);
        }
    }

    public function cancel(RegistrationIntent $intent, User $user): void
    {
        RegistrationIntent::query()->whereKey($intent->id)->where('user_id', $user->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** Where the completed intent opens: a path inside the organization's workspace. */
    public static function destination(RegistrationIntent $intent): ?string
    {
        $path = (string) ($intent->outcome_details['destination'] ?? '');

        // Server-written, but checked anyway: a relative workspace path only.
        if ($path !== '' && preg_match('/^[a-z][a-z0-9-]*(\/[a-z][a-z0-9-]*)*$/', $path) !== 1) {
            $path = '';
        }

        return $intent->organization?->workspaceUrl('/'.$path);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array{name: string, summary: string|null, applications: list<Application>, plan: Plan|null}|null
     */
    private function resolve(string $type, string $code, string $action): ?array
    {
        $trialable = array_column($this->catalog->trialable(), null, 'code');

        if ($type === 'application') {
            $application = $action === 'trial' && isset($trialable[$code])
                ? Application::query()->where('code', $code)->first()
                : null;

            return $application === null ? null : [
                'name' => $trialable[$code]['name'],
                'summary' => $trialable[$code]['tagline'] ?? $trialable[$code]['description'],
                'applications' => [$application],
                'plan' => null,
            ];
        }

        if ($type === 'product') {
            $product = $action === 'trial'
                ? ErpProduct::query()->with('requiredApplications')->where('code', $code)->first()
                : null;

            if ($product === null || ! $product->status->isLive()) {
                return null;
            }

            // Tried through its required applications; all must be open to trials.
            $required = $product->requiredApplications->sortBy('sort_order')->values();
            if ($required->isEmpty() || $required->contains(fn (Application $application) => ! isset($trialable[$application->code]))) {
                return null;
            }

            return [
                'name' => $product->name,
                'summary' => $product->tagline ?? $product->description,
                'applications' => array_values($required->all()),
                'plan' => null,
            ];
        }

        if ($type === 'plan') {
            $plan = Plan::query()->with(['applications', 'product'])->where('code', $code)
                ->where('status', 'active')->where('is_public', true)->first();

            if ($plan === null || ($action === 'trial' && $plan->trial_days < 1) || ($plan->product !== null && ! $plan->product->status->isLive())) {
                return null;
            }

            return [
                'name' => $plan->name,
                'summary' => $plan->description,
                'applications' => array_values($plan->applications->sortBy('sort_order')->all()),
                'plan' => $plan,
            ];
        }

        return null;
    }

    /**
     * @param  list<Application>  $applications
     * @return array{0: string, 1: list<string>}
     */
    private function startTrials(Organization $organization, array $applications, User $user): array
    {
        $started = $have = $used = [];

        foreach ($applications as $application) {
            try {
                $this->entitlements->startTrial($organization, $application, $user);
                $started[] = $application->code;
            } catch (TrialRefused $refused) {
                // Running or paid already: nothing to start, nothing taken away.
                $refused->reason === TrialRefused::USED ? $used[] = $application->code : $have[] = $application->code;
            }
        }

        if ($started === [] && $have === []) {
            throw new IntentRefused(IntentRefused::TRIAL_USED, __('This organization has already had its free trial. Choose a plan to continue.'));
        }

        return [$started === [] ? 'already_active' : 'trial_started', [...$started, ...$have]];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function activatePlan(Organization $organization, Plan $plan, string $action, User $user): array
    {
        $codes = array_values($plan->applications->pluck('code')->all());

        [$live, $had, $waiting] = $this->tenant->run($organization, fn () => [
            Subscription::query()->whereIn('status', PlanRequests::LIVE_STATUSES)
                ->whereHas('items', fn ($items) => $items->where('plan_id', $plan->id)->where('status', 'active'))->exists(),
            PlanRequests::hadPlan($plan->id),
            PlanRequest::query()->where('status', 'pending')->where('type', 'subscribe')->where('plan_id', $plan->id)->exists(),
        ]);

        if ($live) {
            return ['already_active', $codes];
        }

        if ($action === 'trial') {
            if ($had) {
                throw new IntentRefused(IntentRefused::TRIAL_USED, __(':plan has already been tried here. Request it from your plans page instead.', ['plan' => $plan->name]));
            }

            try {
                $this->plans->startTrial($organization, $plan, $user);
            } catch (ValidationException $refused) {
                throw new IntentRefused(IntentRefused::UNAVAILABLE, (string) collect($refused->errors())->flatten()->first());
            }

            return ['trial_started', $codes];
        }

        // Paid plans: the existing request, approved by staff once paid. Nothing is switched on here.
        if (! $waiting) {
            try {
                $this->plans->requestPlan($organization, $plan, $user, null);
            } catch (ValidationException) {
                // Lost a race to an identical request: that one stands.
            }
        }

        return ['requested', $codes];
    }
}
