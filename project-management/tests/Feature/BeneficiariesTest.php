<?php

use App\Models\AuditEvent;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Partnership;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\InvitedAsBeneficiary;
use App\Support\ParticipationInventory;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| An organization that funds others invites the beneficiaries and implementers
| it funds. Each works in its own workspace on the platform, connected only
| once someone who manages it accepts there, and shares grant by grant: the
| donor sees that and nothing else.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    Notification::fake();
    Storage::fake('local');
});

function donorOrganization(string $slug = 'global-fund'): Organization
{
    $organization = test()->organization($slug);
    $organization->forceFill(['capabilities' => [Organization::DONOR]])->save();

    return $organization;
}

/**
 * Signs in a donor's owner, and has them invite an organization. Answers the
 * link the email carries.
 *
 * @param  array<string, mixed>  $details
 */
function inviteBeneficiary(string $email = 'admin@water.example', array $details = [], string $donor = 'global-fund'): string
{
    donorOrganization($donor);
    test()->post(test()->organizationUrl($donor, 'beneficiaries'), [
        'name' => 'Water Now', 'label' => 'implementer', 'email' => $email, 'currency' => 'kes', 'timezone' => 'Africa/Nairobi', ...$details,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitedAsBeneficiary::class, function (InvitedAsBeneficiary $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $url = $notification->url;
        }

        return true;
    });

    return (string) $url;
}

/**
 * The invitation's token, from the link the email carries.
 */
function invitationToken(string $url): string
{
    return basename((string) parse_url($url, PHP_URL_PATH));
}

/**
 * Someone who manages $slug accepts the invitation in that workspace.
 */
function acceptInvitation(string $url, string $slug = 'water-now', ?User $admin = null): User
{
    $admin = test()->actingAsMember($slug, [], owner: true, user: $admin);
    test()->post(test()->organizationUrl($slug, 'partnerships/'.invitationToken($url)))->assertSessionHasNoErrors()->assertRedirect();

    return $admin;
}

/**
 * A donor's owner, a beneficiary whose owner accepted in its workspace, and
 * a grant it recorded against the donor. The test ends signed in as the
 * beneficiary's admin.
 *
 * @return array{donor: User, admin: User, partnership: Partnership, slug: string}
 */
function connectedBeneficiary(): array
{
    $donor = test()->actingAsMember('global-fund', [], owner: true);
    $url = inviteBeneficiary();
    $admin = acceptInvitation($url, 'water-now', User::factory()->create(['email' => 'admin@water.example']));

    return [
        'donor' => $donor,
        'admin' => $admin,
        'partnership' => Partnership::query()->whereNotNull('accepted_at')->sole(),
        'slug' => 'water-now',
    ];
}

function beneficiaryGrant(string $slug, string $reference = 'GF/1', bool $share = true, ?string $funderId = null): Grant
{
    $funderId ??= test()->asTenant($slug, fn () => Funder::query()->orderBy('created_at')->firstOrFail()->id);
    test()->post(test()->organizationUrl($slug, 'grants'), [
        'funder_id' => $funderId, 'reference' => $reference, 'title' => 'Clean water',
        'currency' => 'usd', 'amount' => '100000', 'restricted' => true, 'status' => 'applied',
    ])->assertSessionHasNoErrors();
    $grant = test()->asTenant($slug, fn () => Grant::query()->where('reference', $reference)->firstOrFail());
    test()->post(test()->organizationUrl($slug, "grants/{$grant->id}/award"), ['amount' => '100000', 'on' => '2026-11-01', 'start_on' => '2026-11-01', 'end_on' => '2027-10-31'])->assertSessionHasNoErrors();
    if ($share) {
        test()->put(test()->organizationUrl($slug, "grants/{$grant->id}/portal"), ['portal_enabled' => true, 'portal_budget' => false, 'portal_spending' => false, 'portal_progress' => false])->assertSessionHasNoErrors();
    }

    return test()->asTenant($slug, fn () => $grant->fresh() ?? $grant);
}

it('makes nothing until the invitation is accepted', function () {
    $this->actingAsMember('global-fund', [], owner: true);
    inviteBeneficiary();

    expect(Organization::query()->where('name', 'Water Now')->exists())->toBeFalse()
        ->and(User::findByEmail('admin@water.example'))->toBeNull();

    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/index')
        ->has('partnerships', 1)
        ->where('partnerships.0.name', 'Water Now')
        ->where('partnerships.0.label', 'Project implementer')
        ->where('partnerships.0.status', 'invited'));
});

it('sends the emailed link on to the workspace of the organization invited', function () {
    $this->actingAsMember('global-fund', [], owner: true);
    $url = inviteBeneficiary();
    expect($url)->toBe($this->organizationUrl('global-fund', 'invitations/'.invitationToken($url)));

    auth()->logout();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/partnerships/landing')
        ->where('invitation.donor', 'Global Fund')
        ->where('invitation.organization', 'Water Now'));

    $this->post($url, ['workspace' => 'not a name'])->assertSessionHasErrors('workspace');
    $this->post($url, ['workspace' => 'Water-Now'])->assertRedirect($this->organizationUrl('water-now', 'partnerships/'.invitationToken($url)));
});

it('connects the workspace where it is accepted', function () {
    ['admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();

    $organization = $this->organization('water-now');
    expect($organization->isDonor())->toBeFalse()
        ->and($organization->memberships()->where('user_id', $admin->id)->value('is_owner'))->toBeTrue()
        ->and($partnership->beneficiary_organization_id)->toBe($organization->id)
        ->and($partnership->token_hash)->toBeNull();

    $this->get($this->organizationUrl('water-now'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/home')
        ->where('access.isOwner', true));

    // The donor is in its directory of funders, as any funder is.
    expect($this->asTenant('water-now', fn () => Funder::query()->sole()->name))->toBe('Global Fund');
});

it('lets only those who manage the organization accept, and never for the donor itself', function () {
    $this->actingAsMember('global-fund', [], owner: true);
    $url = inviteBeneficiary();
    $token = invitationToken($url);

    $this->actingAsMember('water-now', ['projects.view']);
    $this->get($this->organizationUrl('water-now', "partnerships/{$token}"))->assertForbidden();
    $this->post($this->organizationUrl('water-now', "partnerships/{$token}"))->assertForbidden();

    $this->actingAsMember('water-now', ['organization.manage']);
    $this->get($this->organizationUrl('water-now', "partnerships/{$token}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/partnerships/show')
        ->where('connectable', true)
        ->where('here', 'Water Now'));

    $this->actingAsMember('global-fund', [], owner: true);
    $this->get($this->organizationUrl('global-fund', "partnerships/{$token}"))->assertInertia(fn (Assert $page) => $page->where('connectable', false));
    $this->post($this->organizationUrl('global-fund', "partnerships/{$token}"))->assertStatus(409);
    expect(Partnership::query()->sole()->accepted_at)->toBeNull();
});

it('takes an invitation once, and not after it is withdrawn or expired', function () {
    $donor = $this->actingAsMember('global-fund', [], owner: true);
    $withdrawn = inviteBeneficiary('a@water.example');
    $this->delete($this->organizationUrl('global-fund', 'beneficiaries/'.Partnership::query()->where('email', 'a@water.example')->sole()->id))->assertSessionHasNoErrors();

    $expired = inviteBeneficiary('b@water.example');
    $this->travel(31)->days();

    auth()->logout();
    $this->get($withdrawn)->assertOk()->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    $this->actingAsMember('water-now', [], owner: true);
    $this->get($this->organizationUrl('water-now', 'partnerships/'.invitationToken($withdrawn)))->assertRedirect()->assertSessionHas('warning');
    $this->post($this->organizationUrl('water-now', 'partnerships/'.invitationToken($withdrawn)))->assertNotFound();
    $this->post($this->organizationUrl('water-now', 'partnerships/'.invitationToken($expired)))->assertNotFound();

    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->actingAs($donor);
    $used = inviteBeneficiary('c@water.example');
    acceptInvitation($used);
    $this->actingAsMember('other-ngo', [], owner: true);
    $this->post($this->organizationUrl('other-ngo', 'partnerships/'.invitationToken($used)))->assertNotFound();
});

it('never hands a donor the funder record of the same name', function () {
    $admin = User::factory()->create(['email' => 'admin@water.example']);
    $this->actingAsMember('old-ngo', [], owner: true, user: $admin);
    $this->post($this->organizationUrl('old-ngo', 'funders'), ['name' => 'Global Fund', 'kind' => 'institutional'])->assertSessionHasNoErrors();
    $real = $this->asTenant('old-ngo', fn () => Funder::query()->sole());
    beneficiaryGrant('old-ngo', 'REAL/1', funderId: $real->id);

    // Anyone can name their workspace after a funder they don't represent.
    $this->actingAsMember('global-fund', [], owner: true);
    $url = inviteBeneficiary();
    acceptInvitation($url, 'old-ngo', $admin);

    $partnership = Partnership::query()->sole();
    expect($partnership->funder_id)->not->toBe($real->id)
        ->and($this->asTenant('old-ngo', fn () => Funder::query()->count()))->toBe(2);

    $this->actingAsMember('global-fund', [], owner: true);
    $this->get($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}"))->assertInertia(fn (Assert $page) => $page->has('grants', 0));
});

it('shows a donor only what each grant shares, the same as any funder', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $shared = beneficiaryGrant('water-now', 'GF/1');
    $private = beneficiaryGrant('water-now', 'GF/2', share: false);
    $this->post($this->organizationUrl('water-now', 'funders'), ['name' => 'Someone Else', 'kind' => 'foundation'])->assertSessionHasNoErrors();
    $otherFunder = $this->asTenant('water-now', fn () => Funder::query()->where('name', 'Someone Else')->sole());
    $another = beneficiaryGrant('water-now', 'ELSE/1', funderId: $otherFunder->id);

    $this->actingAs($donor);
    $base = "beneficiaries/{$partnership->id}";

    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertInertia(fn (Assert $page) => $page
        ->where('partnerships.0.status', 'connected')
        ->where('partnerships.0.grants', 1));
    $response = $this->get($this->organizationUrl('global-fund', $base))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/donor/index')
        ->has('grants', 1)
        ->where('grants.0.reference', 'GF/1'));
    $response->assertDontSee('GF/2')->assertDontSee('ELSE/1');

    $this->get($this->organizationUrl('global-fund', "{$base}/grants/{$shared->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/donor/grant')
        ->where('grant.reference', 'GF/1')
        ->where('projects', []));
    $this->get($this->organizationUrl('global-fund', "{$base}/grants/{$private->id}"))->assertNotFound();
    $this->get($this->organizationUrl('global-fund', "{$base}/grants/{$another->id}"))->assertNotFound();

    // Still inside the donor, and with no way into the beneficiary's own workspace.
    $this->get($this->organizationUrl('water-now'))->assertForbidden();
    $this->get($this->organizationUrl('water-now', "grants/{$shared->id}"))->assertForbidden();
    expect(OrganizationMembership::query()->where('user_id', $donor->id)->where('organization_id', $partnership->beneficiary_organization_id)->exists())->toBeFalse();
});

it('keeps one donor out of another donor\'s beneficiaries', function () {
    ['partnership' => $partnership] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');

    donorOrganization('other-fund');
    $this->actingAsMember('other-fund', [], owner: true);

    $base = "beneficiaries/{$partnership->id}";
    $this->get($this->organizationUrl('other-fund', $base))->assertNotFound();
    $this->get($this->organizationUrl('other-fund', "{$base}/grants/{$grant->id}"))->assertNotFound();
    $this->post($this->organizationUrl('other-fund', "{$base}/grants/{$grant->id}/messages"), ['body' => 'Hello'])->assertNotFound();
});

it('closes a beneficiary to its donor when it is suspended', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $this->organization('water-now')->forceFill(['status' => Organization::SUSPENDED])->save();

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('partnerships.0.grants', 0));
    $this->get($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}"))->assertNotFound();
});

it('carries reports, documents and messages between the two, and tells each side', function () {
    ['donor' => $donor, 'admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/reports"), ['title' => 'First report', 'kind' => 'combined', 'due_on' => '2026-12-31'])->assertSessionHasNoErrors();
    $report = $this->asTenant('water-now', fn () => GrantReport::query()->sole());
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/reports/{$report->id}/documents"), ['document' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $document = $this->asTenant('water-now', fn () => $report->documents()->sole());

    $base = "beneficiaries/{$partnership->id}/grants/{$grant->id}";
    $file = "{$base}/reports/{$report->id}/documents/{$document->id}";

    // A draft stays the beneficiary's.
    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', $file))->assertNotFound();
    $this->post($this->organizationUrl('global-fund', "{$base}/reports/{$report->id}/decision"), ['status' => 'accepted'])->assertSessionHasErrors('status');

    // Submitted, the donor hears on donor.domain.
    $this->actingAs($admin);
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/reports/{$report->id}/status"), ['status' => 'submitted', 'on' => '2026-11-04'])->assertSessionHasNoErrors();
    $told = $this->asTenant('global-fund', fn () => UserNotification::query()->where('user_id', $donor->id)->where('title', 'like', 'A report%')->sole());
    expect($told->url)->toBe("/projects/{$base}");

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', $file))->assertOk();
    $this->post($this->organizationUrl('global-fund', "{$base}/messages"), ['body' => 'Add the water tests'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('global-fund', "{$base}/reports/{$report->id}/decision"), ['status' => 'accepted'])->assertSessionHasNoErrors();
    expect($report->fresh()?->status->value)->toBe('accepted');

    // The beneficiary hears in its own organization.
    expect($this->asTenant('water-now', fn () => UserNotification::query()->where('user_id', $admin->id)->count()))->toBe(2);

    $this->actingAs($admin);
    $this->get($this->organizationUrl('water-now', "grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('portal.messages.0.body', 'Add the water tests')
        ->where('portal.messages.0.fromFunder', true));
});

it('needs permissions at the donor: to invite, to read, and to act', function () {
    ['partnership' => $partnership] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    $base = "beneficiaries/{$partnership->id}";

    $this->actingAsMember('global-fund', ['grants.view']);
    $this->get($this->organizationUrl('global-fund', "{$base}/grants/{$grant->id}"))->assertOk();
    $this->post($this->organizationUrl('global-fund', "{$base}/grants/{$grant->id}/messages"), ['body' => 'Hello'])->assertForbidden();
    $this->post($this->organizationUrl('global-fund', 'beneficiaries'), ['name' => 'X', 'label' => 'beneficiary', 'email' => 'x@example.org', 'currency' => 'USD', 'timezone' => 'UTC'])->assertForbidden();

    $this->actingAsMember('global-fund', []);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertForbidden();
    $this->get($this->organizationUrl('global-fund', $base))->assertForbidden();
});

it('has funding pages only for an organization that funds others', function () {
    $this->actingAsMember('global-fund', [], owner: true);
    donorOrganization();

    // Its funding pages, its own work and its administration.
    foreach (['beneficiaries', 'funding', 'marketplace', 'projects', 'notifications', 'admin/members'] as $path) {
        $this->get($this->organizationUrl('global-fund', $path))->assertOk();
    }
    // With no projects of its own, it lands on its funding dashboard.
    $this->get($this->organizationUrl('global-fund'))->assertRedirect(route('beneficiaries.dashboard', [], false));

    // An organization that funds nobody has no funding pages.
    $this->actingAsMember('water-now', ['projects.view', 'grants.view']);
    foreach (['beneficiaries', 'funding', 'marketplace'] as $path) {
        $this->get($this->organizationUrl('water-now', $path))->assertNotFound();
    }
    $this->get($this->organizationUrl('water-now', 'projects'))->assertOk();
});

it('opens a funding dashboard of what is shared and what waits', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $shared = beneficiaryGrant('water-now', 'GF/1');
    $private = beneficiaryGrant('water-now', 'GF/2', share: false);
    $report = fn (Grant $grant, string $title, string $due) => $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/reports"), ['title' => $title, 'kind' => 'combined', 'due_on' => $due])->assertSessionHasNoErrors();
    $report($shared, 'Late report', '2026-10-01');
    $report($shared, 'Next report', '2026-12-31');
    $report($private, 'Private report', '2026-12-31');
    $this->post($this->organizationUrl('water-now', "grants/{$shared->id}/disbursements"), ['due_on' => '2026-11-15', 'amount' => '25000'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', "grants/{$private->id}/disbursements"), ['due_on' => '2026-11-15', 'amount' => '999'])->assertSessionHasNoErrors();
    $next = $this->asTenant('water-now', fn () => GrantReport::query()->where('title', 'Next report')->sole());
    $this->post($this->organizationUrl('water-now', "grants/{$shared->id}/reports/{$next->id}/status"), ['status' => 'submitted', 'on' => '2026-11-04'])->assertSessionHasNoErrors();

    $this->actingAs($donor);
    $response = $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/dashboard')
        ->where('overview.connected', 1)
        ->where('overview.invited', 0)
        ->where('overview.activeGrants', 1)
        ->where('overview.toDecideCount', 1)
        ->where('overview.toDecide.0.title', 'Next report')
        ->where('overview.toDecide.0.url', "/projects/beneficiaries/{$partnership->id}/grants/{$shared->id}")
        ->where('overview.owedCount', 1)
        ->where('overview.owed.0.title', 'Late report')
        ->where('overview.owed.0.overdue', true)
        ->where('overview.paymentsCount', 1)
        ->where('overview.payments.0.amountMinor', 2500000)
        ->where('overview.payments.0.overdue', false)
        ->where('overview.funding', [['currency' => 'USD', 'amountMinor' => 10000000, 'receivedMinor' => 0]])
        ->has('overview.grants', 1));

    // What is not shared is not there at all.
    $response->assertDontSee('GF/2')->assertDontSee('Private report');
});

it('totals a donor\'s funding per currency, never across them', function () {
    ['donor' => $donor] = connectedBeneficiary();
    beneficiaryGrant('water-now', 'GF/1');
    $this->post($this->organizationUrl('water-now', 'grants'), [
        'funder_id' => $this->asTenant('water-now', fn () => Funder::query()->sole()->id), 'reference' => 'GF/KES', 'title' => 'Boreholes',
        'currency' => 'kes', 'amount' => '500000', 'restricted' => true, 'status' => 'applied',
    ])->assertSessionHasNoErrors();
    $grant = $this->asTenant('water-now', fn () => Grant::query()->where('reference', 'GF/KES')->sole());
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/award"), ['amount' => '500000', 'on' => '2026-11-01', 'start_on' => '2026-11-01', 'end_on' => '2027-10-31'])->assertSessionHasNoErrors();
    $this->put($this->organizationUrl('water-now', "grants/{$grant->id}/portal"), ['portal_enabled' => true, 'portal_budget' => false, 'portal_spending' => false, 'portal_progress' => false])->assertSessionHasNoErrors();

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertInertia(fn (Assert $page) => $page->where('overview.funding', [
        ['currency' => 'KES', 'amountMinor' => 50000000, 'receivedMinor' => 0],
        ['currency' => 'USD', 'amountMinor' => 10000000, 'receivedMinor' => 0],
    ]));
});

it('gives the dashboard to any donor member, with figures only for those who may see grants', function () {
    connectedBeneficiary();

    $this->actingAsMember('global-fund', []);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview', null));

    $this->actingAsMember('global-fund', ['grants.view']);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.connected', 1));
});

it('opens a new donor on an empty dashboard, and a suspended beneficiary drops out', function () {
    $this->actingAsMember('global-fund', [], owner: true);
    donorOrganization();
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertInertia(fn (Assert $page) => $page->where('overview.connected', 0)->where('overview.grants', []));

    ['donor' => $donor] = connectedBeneficiary();
    beneficiaryGrant('water-now');
    $this->organization('water-now')->forceFill(['status' => Organization::SUSPENDED])->save();
    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.connected', 0)->where('overview.activeGrants', 0));
});

function fundedProject(Grant $grant, string $code = 'WASH'): Project
{
    test()->post(test()->organizationUrl('water-now', 'projects'), ['code' => $code, 'name' => "Project {$code}", 'status' => 'active'])->assertSessionHasNoErrors();
    $project = test()->asTenant('water-now', fn () => Project::query()->where('code', $code)->firstOrFail());
    $project->forceFill(['currency' => 'USD', 'status' => Project::ACTIVE])->save();
    test()->post(test()->organizationUrl('water-now', "grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '1000'])->assertSessionHasNoErrors();

    return $project;
}

function shareParts(Grant $grant, array $parts): void
{
    test()->put(test()->organizationUrl('water-now', "grants/{$grant->id}/portal"), ['portal_enabled' => true, 'portal_budget' => false, 'portal_spending' => false, 'portal_progress' => false, ...$parts])->assertSessionHasNoErrors();
}

function projectTask(Project $project, string $title, array $attributes = []): Task
{
    test()->post(test()->organizationUrl('water-now', "projects/{$project->id}/tasks"), ['title' => $title, 'priority' => 'medium'])->assertSessionHasNoErrors();

    return test()->asTenant('water-now', function () use ($project, $title, $attributes) {
        $task = Task::query()->where('project_id', $project->id)->where('title', $title)->firstOrFail();
        $task->forceFill($attributes)->save();

        return $task;
    });
}

it('adds up projects and shared tasks on the dashboard, with no count for tasks not shared', function () {
    ['donor' => $donor, 'admin' => $admin] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    $project = fundedProject($grant);
    projectTask($project, 'Late one', ['due_on' => '2020-01-01']);
    projectTask($project, 'Another');

    $this->actingAs($donor);
    $url = fn () => $this->organizationUrl('global-fund', 'funding');
    $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->where('overview.projectCount', 1)
        ->where('overview.tasksShared', false)
        ->where('overview.openTasks', 0)
        ->where('overview.overdueTasks', 0));

    $this->actingAs($admin);
    shareParts($grant, ['portal_tasks' => true]);

    $this->actingAs($donor);
    $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->where('overview.tasksShared', true)
        ->where('overview.openTasks', 2)
        ->where('overview.overdueTasks', 1));
});

it('lists a donor\'s reports across shared grants, and none from others', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $shared = beneficiaryGrant('water-now', 'GF/1');
    $private = beneficiaryGrant('water-now', 'GF/2', share: false);
    foreach ([[$shared, 'Shared report'], [$private, 'Private report']] as [$grant, $title]) {
        $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/reports"), ['title' => $title, 'kind' => 'combined', 'due_on' => '2020-01-01'])->assertSessionHasNoErrors();
    }

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries/reports'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/reports')
        ->has('reports', 1)
        ->where('reports.0.title', 'Shared report')
        ->where('reports.0.grant', 'GF/1')
        ->where('reports.0.overdue', true)
        ->where('reports.0.url', "/projects/beneficiaries/{$partnership->id}/grants/{$shared->id}"))
        ->assertDontSee('Private report');
});

it('shows a funded project\'s status only when a grant shares progress', function () {
    ['donor' => $donor] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    fundedProject($grant);

    $this->actingAs($donor);
    $url = fn () => $this->organizationUrl('global-fund', 'beneficiaries/projects');
    $this->get($url())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/projects')
        ->where('projects.0.code', 'WASH')
        ->where('projects.0.progress.status', null)
        ->where('projects.0.progress.percent', null)
        ->where('projects.0.tasks', null));

    $this->actingAs(User::query()->where('email', 'admin@water.example')->firstOrFail());
    shareParts($grant, ['portal_progress' => true]);

    $this->actingAs($donor);
    $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.progress.status', 'active')
        ->where('projects.0.progress.percent', 0)
        ->where('projects.0.tasks', null));
});

it('lists a project once when two shared grants fund it, with what either shares', function () {
    ['donor' => $donor] = connectedBeneficiary();
    $first = beneficiaryGrant('water-now', 'GF/1');
    $second = beneficiaryGrant('water-now', 'GF/2');
    $project = fundedProject($first);
    $this->post($this->organizationUrl('water-now', "grants/{$second->id}/allocations"), ['project_id' => $project->id, 'amount' => '1000'])->assertSessionHasNoErrors();
    shareParts($second, ['portal_progress' => true]);

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries/projects'))->assertInertia(fn (Assert $page) => $page
        ->has('projects', 1)
        ->has('projects.0.grants', 2)
        ->where('projects.0.progress.status', 'active'));
});

it('keeps tasks from a donor until the grant shares them, and then only the safe fields', function () {
    ['donor' => $donor, 'admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    $project = fundedProject($grant);
    $late = projectTask($project, 'Drill the borehole', ['due_on' => '2020-01-01', 'description' => 'Secret description']);
    projectTask($project, 'Finished survey', ['status' => Task::DONE, 'completed_at' => now()]);
    $this->asTenant('water-now', fn () => Task::query()->forceCreate([
        'organization_id' => $late->organization_id, 'project_id' => $project->id, 'list_id' => $late->list_id, 'parent_id' => $late->id,
        'number' => 'WASH-99', 'title' => 'A subtask', 'status' => Task::TODO, 'priority' => $late->priority, 'created_by' => $late->created_by,
    ]));

    // Sharing progress is not sharing tasks.
    shareParts($grant, ['portal_progress' => true]);
    $this->actingAs($donor);
    $url = fn () => $this->organizationUrl('global-fund', 'beneficiaries/tasks');
    $this->get($url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('portal/beneficiaries/tasks')->where('tasks', [])->where('total', 0))->assertDontSee('Drill the borehole');
    $grantUrl = $this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/grants/{$grant->id}");
    $this->get($grantUrl)->assertInertia(fn (Assert $page) => $page->where('projects.0.tasks', null));

    $this->actingAs($admin);
    shareParts($grant, ['portal_tasks' => true]);

    $this->actingAs($donor);
    $response = $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 2)
        ->where('tasks.0.title', 'Drill the borehole')
        ->where('tasks.0.overdue', true)
        ->where('tasks.1.title', 'Finished survey')
        ->where('tasks.1.overdue', false)
        ->where('tasks.0', fn ($task) => collect($task)->keys()->sort()->values()->all() === ['beneficiary', 'completedOn', 'dueOn', 'id', 'number', 'overdue', 'project', 'status', 'title']));
    $response->assertDontSee('Secret description')->assertDontSee('A subtask');

    $this->get($this->organizationUrl('global-fund', 'beneficiaries/projects'))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.tasks', ['open' => 1, 'done' => 1, 'overdue' => 1]));
    $this->get($grantUrl)->assertInertia(fn (Assert $page) => $page->has('projects.0.tasks', 2)->where('shares.tasks', true));
});

it('stops sharing tasks when a save leaves the switch out', function () {
    connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    shareParts($grant, ['portal_tasks' => true]);
    shareParts($grant, []);

    expect($this->asTenant('water-now', fn () => $grant->fresh()?->portal_tasks))->toBeFalse();
});

it('keeps the donor\'s reports, projects and tasks to those who may see grants', function (string $page) {
    connectedBeneficiary();

    $this->actingAsMember('global-fund', []);
    $this->get($this->organizationUrl('global-fund', "beneficiaries/{$page}"))->assertForbidden();

    $this->actingAsMember('global-fund', ['grants.view']);
    $this->get($this->organizationUrl('global-fund', "beneficiaries/{$page}"))->assertOk();

    $this->actingAs(User::query()->where('email', 'admin@water.example')->firstOrFail());
    $this->get($this->organizationUrl('water-now', "beneficiaries/{$page}"))->assertNotFound();
})->with(['reports', 'projects', 'tasks']);

it('lists shared grants and payments for the donor, totalled per currency and counted once', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $shared = beneficiaryGrant('water-now', 'GF/1');
    beneficiaryGrant('water-now', 'GF/2', share: false);
    // Two projects funded by the same grant: the award still counts once.
    fundedProject($shared);
    fundedProject($shared, 'SAN');
    $this->post($this->organizationUrl('water-now', "grants/{$shared->id}/disbursements"), ['due_on' => '2026-11-01', 'amount' => '40000', 'note' => 'Internal: first tranche'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', "grants/{$shared->id}/disbursements"), ['due_on' => '2027-05-01', 'amount' => '60000'])->assertSessionHasNoErrors();

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries/grants'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/beneficiaries/grants')
        ->has('grants', 1)
        ->where('grants.0.reference', 'GF/1')
        ->where('grants.0.beneficiary', 'Water Now')
        ->where('grants.0.url', "/projects/beneficiaries/{$partnership->id}/grants/{$shared->id}")
        ->has('payments', 2)
        ->where('payments.0.overdue', true)
        ->where('totals', [['currency' => 'USD', 'awardedMinor' => 10_000_000, 'receivedMinor' => 0, 'scheduledMinor' => 10_000_000]]))
        ->assertDontSee('GF/2')->assertDontSee('Internal:');

    // Another donor sees none of it.
    donorOrganization('other-fund');
    $this->actingAsMember('other-fund', ['grants.view']);
    $this->get($this->organizationUrl('other-fund', 'beneficiaries/grants'))->assertInertia(fn (Assert $page) => $page
        ->has('grants', 0)->has('payments', 0)->where('totals', []));
});

it('shares milestones and commitments only when their own switches are on', function () {
    ['donor' => $donor, 'partnership' => $partnership] = connectedBeneficiary();
    $grant = beneficiaryGrant('water-now');
    $project = fundedProject($grant);
    $this->asTenant('water-now', fn () => $project->milestones()->create(['name' => 'Boreholes drilled', 'due_on' => '2027-01-15']));
    shareParts($grant, ['portal_progress' => true]);

    $this->actingAs($donor);
    $url = fn () => $this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/grants/{$grant->id}");
    $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.milestones', null)
        ->where('projects.0.commitments', null))
        ->assertDontSee('Boreholes drilled');
    $this->get($this->organizationUrl('global-fund', 'beneficiaries/projects'))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.progress.milestones', 0)
        ->where('projects.0.progress.nextMilestone', null));

    $this->actingAs(User::query()->where('email', 'admin@water.example')->firstOrFail());
    shareParts($grant, ['portal_milestones' => true]);

    $this->actingAs($donor);
    $this->get($url())->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.progress', null)
        ->where('projects.0.milestones.0.name', 'Boreholes drilled'));
});

it('offers to record a grant for a connected donor, with its funder chosen', function () {
    ['partnership' => $partnership] = connectedBeneficiary();

    $this->get($this->organizationUrl('water-now', 'donor-connections'))->assertInertia(fn (Assert $page) => $page
        ->where('connected.0.grants', 0)
        ->where('connected.0.recordGrantUrl', "/projects/grants/create?funder={$partnership->funder_id}"));
    $this->get($this->organizationUrl('water-now', "grants/create?funder={$partnership->funder_id}"))->assertInertia(fn (Assert $page) => $page
        ->where('funderId', $partnership->funder_id));
    // Not a funder of this organization: nothing is chosen.
    $this->get($this->organizationUrl('water-now', 'grants/create?funder=00000000-0000-0000-0000-000000000000'))->assertInertia(fn (Assert $page) => $page
        ->where('funderId', null));

    beneficiaryGrant('water-now');
    $this->get($this->organizationUrl('water-now', 'donor-connections'))->assertInertia(fn (Assert $page) => $page
        ->where('connected.0.grants', 1));
});

it('inventories donor and partner records for the participation model, changing none', function () {
    ['admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $this->actingAs($admin);
    $grant = beneficiaryGrant('water-now');
    shareParts($grant, ['portal_budget' => true]);
    $new = fundedProject($grant, 'WASH');
    $joined = fundedProject($grant, 'SANI');
    // The donor already takes part in SANI, without the budget the grant shares.
    acceptProjectInvitation(inviteToProject($joined, 'global-fund', 'donor', ['view'], owner: 'water-now'), 'global-fund');

    $water = $this->organization('water-now');
    $insert = fn (string $table, array $row) => DB::table($table)->insert(['id' => (string) Str::uuid(), 'organization_id' => $water->id, 'created_at' => now(), 'updated_at' => now(), ...$row]);
    $insert('mel_partners', ['name' => 'Field NGO', 'kind' => 'ngo']);
    $insert('mel_partners', ['name' => 'field-ngo ', 'kind' => 'ngo']);
    $insert('mel_partners', ['name' => 'Global Fund', 'kind' => 'ngo']);
    $offPlatform = (string) Str::uuid();
    DB::table('funders')->insert(['id' => $offPlatform, 'organization_id' => $water->id, 'name' => 'Local Trust', 'kind' => 'foundation', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $insert('funder_contacts', ['funder_id' => $offPlatform, 'user_id' => User::factory()->create()->id]);

    $tables = ['partnerships', 'project_participants', 'mel_partners', 'funders', 'funder_contacts', 'grants', 'project_invitations'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();

    $inventory = ParticipationInventory::run($water->id);
    $found = collect($inventory['findings'])->map(fn (array $finding) => "{$finding['kind']} {$finding['subject']}")->all();

    expect($inventory['counts'])->toMatchArray(['partnerships_accepted' => 1, 'participants' => 1, 'mel_partners' => 3, 'funder_contacts' => 1])
        ->and($found)->toContain('candidate WASH ← Global Fund (GF/1)')
        ->toContain('already_participant SANI ← Global Fund (GF/1)')
        ->toContain('visibility_conflict SANI ← Global Fund (GF/1)')
        ->toContain('duplicate_mel_partner Field NGO / field-ngo ')
        ->toContain('mel_partner_matches_organization Global Fund')
        ->toContain('unlinked_funder Local Trust')
        ->not->toContain('candidate SANI ← Global Fund (GF/1)');
    expect($partnership->funder_id)->not->toBeNull();

    $this->artisan('projects:participation-inventory --organization=water-now')
        ->expectsOutputToContain('partnerships_accepted: 1')
        ->expectsOutputToContain('candidate [water-now] WASH ← Global Fund (GF/1)')
        ->assertExitCode(0);

    // Read-only: every record as it was.
    expect(collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all())->toBe($before);
    expect($new->id)->not->toBeEmpty();
});

/*
| Phase 5, grant agreements (App\Actions\GrantAgreements): the recipient
| proposes the terms, the donor approves them, then each payment is
| requested, approved, recorded as sent by the donor, and confirmed (or
| disputed) by the recipient. Nothing is marked received for the recipient.
*/

/**
 * @return list<GrantDisbursement>
 */
function agreementPayments(Grant $grant): array
{
    test()->post(test()->organizationUrl('water-now', "grants/{$grant->id}/disbursements"), ['due_on' => '2026-11-01', 'amount' => '60000'])->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl('water-now', "grants/{$grant->id}/disbursements"), ['due_on' => '2027-05-01', 'amount' => '40000'])->assertSessionHasNoErrors();

    return test()->asTenant('water-now', fn () => GrantDisbursement::query()->where('grant_id', $grant->id)->orderBy('due_on')->get()->all());
}

it('agrees a grant and moves a payment from request to confirmed receipt, both parties acting', function () {
    ['donor' => $donor, 'admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $this->actingAs($admin);
    $grant = beneficiaryGrant('water-now', share: false);
    [$first] = agreementPayments($grant);
    $grantUrl = fn (string $path = '') => $this->organizationUrl('water-now', "grants/{$grant->id}{$path}");
    $donorUrl = fn (string $path = '') => $this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/grants/{$grant->id}{$path}");

    // No payment is requested before the donor approves the terms.
    $this->post($grantUrl("/disbursements/{$first->id}/request"))->assertSessionHasErrors('disbursement');
    $this->flushSession();
    $this->get($grantUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->where('agreement.status', null)->where('agreement.canPropose', true)->where('disbursements.0.status', 'planned'));
    $this->post($grantUrl('/agreement'), ['note' => 'As agreed in our call.'])->assertSessionHasNoErrors();
    $this->post($grantUrl('/agreement'))->assertSessionHasErrors('agreement');
    $this->flushSession();

    // The donor sees the terms, though the grant is not shared on its portal.
    $this->actingAs($donor);
    $this->get($donorUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/donor/grant')->where('agreement.status', 'proposed')->where('agreement.proposedMinor', 10_000_000)->where('canAgree', true));
    $this->post($donorUrl('/agreement/decision'), ['decision' => 'decline'])->assertSessionHasErrors('note');
    $this->flushSession();
    $this->post($donorUrl('/agreement/decision'), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $stored = $this->asTenant('water-now', fn () => $grant->fresh());
    expect($stored?->agreement_status)->toBe('approved')->and($stored?->agreement_amount_minor)->toBe(10_000_000);

    // The approved amount is a ceiling for the recipient.
    $this->actingAs($admin);
    $this->post($grantUrl('/amendments'), ['amount' => '120000', 'effective_on' => '2026-11-04', 'reason' => 'More wells'])->assertSessionHasErrors('amount');
    $this->flushSession();

    $this->post($grantUrl("/disbursements/{$first->id}/request"))->assertSessionHasNoErrors();
    $this->delete($grantUrl("/disbursements/{$first->id}"))->assertSessionHasErrors('disbursement');
    $this->flushSession();
    expect($this->asTenant('global-fund', fn () => UserNotification::query()->where('user_id', $donor->id)->where('title', 'like', '%requests%')->count()))->toBe(1);

    // The donor approves and records its transfer; that is not a receipt.
    $this->actingAs($donor);
    $this->post($donorUrl("/payments/{$first->id}/sent"), ['sent_on' => '2026-11-04', 'reference' => 'TRX-1'])->assertSessionHasErrors('disbursement');
    $this->flushSession();
    $this->post($donorUrl("/payments/{$first->id}/decision"), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->post($donorUrl("/payments/{$first->id}/sent"), ['sent_on' => '2026-11-05', 'reference' => 'TRX-1'])->assertSessionHasErrors('sent_on');
    $this->flushSession();
    $this->post($donorUrl("/payments/{$first->id}/sent"), ['sent_on' => '2026-11-04', 'reference' => 'TRX-1'])->assertSessionHasNoErrors();
    $sent = $this->asTenant('water-now', fn () => $first->fresh());
    expect($sent?->status)->toBe('sent')->and($sent?->received_on)->toBeNull();

    // The recipient disputes, the donor corrects, the recipient confirms.
    $this->actingAs($admin);
    $this->post($grantUrl("/disbursements/{$first->id}/confirm"), ['received' => '0'])->assertSessionHasErrors('note');
    $this->flushSession();
    $this->post($grantUrl("/disbursements/{$first->id}/confirm"), ['received' => '0', 'note' => 'Only half arrived.'])->assertSessionHasNoErrors();
    $this->actingAs($donor);
    $this->post($donorUrl("/payments/{$first->id}/sent"), ['sent_on' => '2026-11-04', 'reference' => 'TRX-1b'])->assertSessionHasNoErrors();
    $this->actingAs($admin);
    $this->post($grantUrl("/disbursements/{$first->id}/confirm"), ['received' => '1', 'on' => '2026-11-04'])->assertSessionHasNoErrors();

    $done = $this->asTenant('water-now', fn () => $first->fresh());
    expect($done?->status)->toBe('received')->and($done?->received_on?->toDateString())->toBe('2026-11-04')->and($done?->sent_reference)->toBe('TRX-1b');
    // Each step recorded on both sides.
    foreach (['grant.agreement_proposed', 'grant.agreement_approved', 'grant.disbursement_requested', 'grant.disbursement_approved', 'grant.disbursement_sent', 'grant.disbursement_disputed', 'grant.disbursement_confirmed'] as $action) {
        expect(AuditEvent::query()->withoutGlobalScopes()->where('action', $action)->distinct()->count('organization_id'))->toBe(2, $action);
    }
});

it('keeps agreements and payments between the two connected organizations', function () {
    ['donor' => $donor, 'admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $this->actingAs($admin);
    $grant = beneficiaryGrant('water-now', share: false);
    [$first] = agreementPayments($grant);
    // A grant from another funder, not connected on Tatook.
    $otherFunder = $this->asTenant('water-now', fn () => Funder::query()->create(['name' => 'Other Fund', 'kind' => 'institutional']));
    $other = beneficiaryGrant('water-now', 'OF/1', share: true, funderId: $otherFunder->id);
    $this->post($this->organizationUrl('water-now', "grants/{$other->id}/disbursements"), ['due_on' => '2026-12-01', 'amount' => '1000'])->assertSessionHasNoErrors();
    $otherPayment = $this->asTenant('water-now', fn () => GrantDisbursement::query()->where('grant_id', $other->id)->sole());

    $this->post($this->organizationUrl('water-now', "grants/{$other->id}/agreement"))->assertSessionHasErrors('agreement');
    $this->flushSession();
    $this->get($this->organizationUrl('water-now', "grants/{$other->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('agreement', null));
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/agreement"))->assertSessionHasNoErrors();

    $base = "beneficiaries/{$partnership->id}/grants/{$grant->id}";
    // Not the donor's grant, or its payment, through its own URL.
    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}/grants/{$other->id}"))->assertNotFound();
    $this->post($this->organizationUrl('global-fund', "{$base}/payments/{$otherPayment->id}/decision"), ['decision' => 'approve'])->assertNotFound();

    // A donor member without grants.manage reads, and decides nothing.
    $this->actingAsMember('global-fund', ['grants.view']);
    $this->get($this->organizationUrl('global-fund', $base))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAgree', false));
    $this->post($this->organizationUrl('global-fund', "{$base}/agreement/decision"), ['decision' => 'approve'])->assertForbidden();

    // Another organization reaches nothing.
    $this->actingAsMember('other-org', ['grants.view', 'grants.manage']);
    $this->post($this->organizationUrl('other-org', "{$base}/agreement/decision"), ['decision' => 'approve'])->assertNotFound();
    $this->post($this->organizationUrl('other-org', "{$base}/payments/{$first->id}/decision"), ['decision' => 'approve'])->assertNotFound();

    expect($this->asTenant('water-now', fn () => $grant->fresh()?->agreement_status))->toBe('proposed')
        ->and($this->asTenant('water-now', fn () => $first->fresh()?->status))->toBe('planned');
});

it('puts terms and payments waiting on each party on its dashboard, and nothing on anyone else', function () {
    ['donor' => $donor, 'admin' => $admin, 'partnership' => $partnership] = connectedBeneficiary();
    $this->actingAs($admin);
    $grant = beneficiaryGrant('water-now', share: false);
    [$first] = agreementPayments($grant);
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/agreement"))->assertSessionHasNoErrors();

    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.approvalsCount', 1)->where('overview.approvals.0.kind', 'terms')->where('overview.approvals.0.beneficiary', 'Water Now'));
    $base = "beneficiaries/{$partnership->id}/grants/{$grant->id}";
    $this->post($this->organizationUrl('global-fund', "{$base}/agreement/decision"), ['decision' => 'approve'])->assertSessionHasNoErrors();

    $this->actingAs($admin);
    $this->post($this->organizationUrl('water-now', "grants/{$grant->id}/disbursements/{$first->id}/request"))->assertSessionHasNoErrors();
    $this->actingAs($donor);
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.approvalsCount', 1)->where('overview.approvals.0.kind', 'request')->where('overview.approvals.0.amountMinor', 6_000_000));
    $this->post($this->organizationUrl('global-fund', "{$base}/payments/{$first->id}/decision"), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('global-fund', "{$base}/payments/{$first->id}/sent"), ['sent_on' => '2026-11-04', 'reference' => 'TRX-9'])->assertSessionHasNoErrors();
    $this->get($this->organizationUrl('global-fund', 'funding'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.approvalsCount', 0));

    // Now it waits on the recipient: on its home.
    $this->actingAs($admin);
    $this->get($this->organizationUrl('water-now', ''))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/home')->where('partnerWaiting.count', 1)->where('partnerWaiting.items.0.kind', 'confirm'));

    // Another organization's home lists none of it.
    $this->actingAsMember('other-org', ['projects.view', 'projects.tasks.manage', 'grants.view', 'grants.manage']);
    $this->get($this->organizationUrl('other-org', ''))->assertOk()->assertInertia(fn (Assert $page) => $page->where('partnerWaiting.count', 0));
});
