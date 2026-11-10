<?php

use App\Models\Funder;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Partnership;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\InvitedAsBeneficiary;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
