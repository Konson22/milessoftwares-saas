<?php

use App\Access\Catalog;
use App\Actions\Budgets;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Http\Middleware\ConfineFunderContacts;
use App\Models\AuditEvent;
use App\Models\Funder;
use App\Models\FunderContact;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\OrganizationMembership;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Api\PlatformApi;

/*
| The donor portal: someone invited to act for a funder signs in on the
| organization's subdomain and sees only what each grant shares with them.
| They hold no permissions, are kept to the portal, and can accept a submitted
| report, ask for a revision, and write to the organization.
*/

const PORTAL_STAFF = ['projects.view', 'projects.manage', 'projects.finance.view', 'grants.view', 'grants.manage'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    Storage::fake('local');
    $this->staff = $this->actingAsMember('acme', PORTAL_STAFF);
});

function portalUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function portalFunder(string $name = 'ECHO'): Funder
{
    test()->post(portalUrl('funders'), ['name' => $name, 'kind' => 'institutional'])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Funder::query()->where('name', $name)->firstOrFail());
}

function portalGrant(Funder $funder, string $reference = 'ECHO/1', bool $award = true): Grant
{
    test()->post(portalUrl('grants'), [
        'funder_id' => $funder->id, 'reference' => $reference, 'title' => 'Clean water',
        'currency' => 'usd', 'amount' => '100000', 'restricted' => true, 'status' => 'applied',
    ])->assertSessionHasNoErrors();
    $grant = test()->asTenant('acme', fn () => Grant::query()->where('reference', $reference)->firstOrFail());
    if ($award) {
        test()->post(portalUrl("grants/{$grant->id}/award"), ['amount' => '100000', 'on' => '2026-11-01', 'start_on' => '2026-11-01', 'end_on' => '2027-10-31'])->assertSessionHasNoErrors();
    }

    return $grant->fresh() ?? $grant;
}

/**
 * @param  array<string, bool>  $parts
 */
function portalShare(Grant $grant, array $parts = []): void
{
    test()->put(portalUrl("grants/{$grant->id}/portal"), [
        'portal_enabled' => true, 'portal_budget' => false, 'portal_spending' => false, 'portal_progress' => false, ...$parts,
    ])->assertSessionHasNoErrors()->assertRedirect();
}

/**
 * The staff member adds the contact here; the platform gives them a place in
 * the workspace with the Funder contact role; their first visit makes them
 * the funder's contact. Leaves the test signed in as the contact.
 */
function portalContact(Grant $grant, string $email = 'officer@echo.example'): User
{
    test()->post(portalUrl("grants/{$grant->id}/portal/contacts"), ['email' => $email])->assertSessionHasNoErrors()->assertRedirect();

    $user = User::findByEmail($email) ?? User::factory()->create(['email' => $email, 'name' => 'Donor Officer']);
    test()->platform()->grant('acme', $user->id, [['role' => 'funder_contact', 'permissions' => Catalog::rolePermissions('funder_contact')]]);
    app(PlatformApi::class)->forget(test()->organization('acme')->id);
    test()->actingAs($user);
    test()->get(portalUrl('donor'));

    return $user;
}

function portalReport(Grant $grant, string $title = 'First report', ?string $notes = null): GrantReport
{
    test()->post(portalUrl("grants/{$grant->id}/reports"), ['title' => $title, 'kind' => 'combined', 'due_on' => '2026-12-31', 'notes' => $notes])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => GrantReport::query()->where('title', $title)->firstOrFail());
}

function portalSubmit(GrantReport $report): void
{
    test()->post(portalUrl("grants/{$report->grant_id}/reports/{$report->id}/status"), ['status' => 'submitted', 'on' => '2026-11-04'])->assertSessionHasNoErrors();
}

function portalProject(Grant $grant, string $code = 'WASH'): Project
{
    test()->post(portalUrl('projects'), ['code' => $code, 'name' => "Project {$code}", 'status' => 'active'])->assertSessionHasNoErrors();
    $project = test()->asTenant('acme', fn () => Project::query()->where('code', $code)->firstOrFail());
    $project->forceFill(['currency' => 'USD'])->save();
    test()->post(portalUrl("grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '60000'])->assertSessionHasNoErrors();

    return $project;
}

function portalExpense(Project $project, ?Grant $grant, string $description, ExpenseStatus $status, User $by): void
{
    test()->asTenant('acme', fn () => $project->expenses()->create([
        'category' => CostCategory::Materials,
        'description' => $description,
        'supplier' => 'Secret Supplier Ltd',
        'amount_minor' => 50000,
        'grant_id' => $grant?->id,
        'status' => $status,
        'direct' => true,
        'spent_on' => $status === ExpenseStatus::Paid ? '2026-11-02' : null,
        'payment_reference' => 'SECRET-REF',
        'recorded_by' => $by->id,
    ]));
}

it('lets an invited contact in, with no roles and outside the people directory', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $contact = portalContact($grant);

    $this->get(portalUrl())->assertRedirect(portalUrl('donor'));
    $this->get(portalUrl('donor'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/donor/index')
        ->where('funder', 'ECHO')
        ->where('donorPortal.confined', true)
        ->has('grants', 1)
        ->where('grants.0.reference', 'ECHO/1'));

    expect($this->asTenant('acme', fn () => Person::query()->where('user_id', $contact->id)->exists()))->toBeFalse()
        ->and($this->asTenant('acme', fn () => FunderContact::forUser($contact)?->funder->name))->toBe('ECHO');
});

it('keeps a contact out of every other page of the organization', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    portalContact($grant);

    $checked = 0;
    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        $confined = in_array(ConfineFunderContacts::class, $route->gatherMiddleware(), true);
        if (! $confined || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')
            || $name === 'home' || str_starts_with($name, 'donor.') || str_starts_with($name, 'notifications.')) {
            continue;
        }

        $this->get(portalUrl((string) preg_replace('#^projects/?#', '', $route->uri())))->assertForbidden();
        $checked++;
    }

    // Those any member could open otherwise are among them.
    expect($checked)->toBeGreaterThan(25);
    foreach (['my-work', 'approvals', 'search', 'petty-cash', 'grants', 'people'] as $path) {
        $this->get(portalUrl($path))->assertForbidden();
    }
    $this->get(portalUrl("grants/{$grant->id}"))->assertForbidden();
    $this->post(portalUrl("grants/{$grant->id}/messages"), ['body' => 'Staff side'])->assertForbidden();
    $this->get(portalUrl('notifications'))->assertOk();
});

it('shares nothing until the grant is shared, and hides what is not active', function () {
    $funder = portalFunder();
    $grant = portalGrant($funder);
    $pipeline = portalGrant($funder, 'ECHO/2', award: false);
    portalShare($pipeline);
    $contact = portalContact($grant);

    $this->get(portalUrl('donor'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('grants', 0));
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertNotFound();
    $this->get(portalUrl("donor/grants/{$pipeline->id}"))->assertNotFound();

    $this->actingAs($this->staff);
    portalShare($grant);
    $this->actingAs($contact);
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertOk();

    $this->actingAs($this->staff);
    portalShare($grant, ['portal_enabled' => false]);
    $this->actingAs($contact);
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertNotFound();
});

it('answers only to contacts, and only for their own funder', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $other = portalGrant(portalFunder('USAID'), 'USAID/1');
    portalShare($other);
    $report = portalReport($other);
    portalSubmit($report);

    // Staff don't use the portal, nor does the owner.
    $this->get(portalUrl('donor'))->assertNotFound();
    $this->actingAsMember('acme', [], owner: true);
    $this->get(portalUrl('donor'))->assertNotFound();

    $this->actingAs($this->staff);
    portalContact($grant);
    $this->get(portalUrl('donor'))->assertInertia(fn (Assert $page) => $page->has('grants', 1)->where('grants.0.reference', 'ECHO/1'));
    $this->get(portalUrl("donor/grants/{$other->id}"))->assertNotFound();
    $this->post(portalUrl("donor/grants/{$other->id}/messages"), ['body' => 'Hello'])->assertNotFound();
    $this->post(portalUrl("donor/grants/{$other->id}/reports/{$report->id}/decision"), ['status' => 'accepted'])->assertNotFound();
    expect($report->fresh()?->status->value)->toBe('submitted');
});

it('does not open another organization to a contact', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    portalContact($grant);

    $this->organization('globex');
    $this->get(portalUrl('donor', 'globex'))->assertForbidden();
    $this->get(portalUrl("donor/grants/{$grant->id}", 'globex'))->assertForbidden();
});

it('shows each part only when its switch is on, with the named fields only', function () {
    $grant = portalGrant(portalFunder());
    $project = portalProject($grant);
    $elsewhere = portalProject(portalGrant(portalFunder('USAID'), 'USAID/1'), 'FOOD');
    portalExpense($project, $grant, 'Hand pumps', ExpenseStatus::Paid, $this->staff);
    portalExpense($project, $grant, 'Pipes on order', ExpenseStatus::Approved, $this->staff);
    portalExpense($project, null, 'Paid from core funds', ExpenseStatus::Paid, $this->staff);
    portalExpense($elsewhere, null, 'Another donor pays', ExpenseStatus::Paid, $this->staff);
    portalReport($grant, 'First report', 'Internal: the numbers are weak');
    $this->post(portalUrl("grants/{$grant->id}/disbursements"), ['due_on' => '2026-11-15', 'amount' => '60000', 'note' => 'Internal: chase them'])->assertSessionHasNoErrors();
    portalShare($grant);
    $contact = portalContact($grant);

    $response = $this->get(portalUrl("donor/grants/{$grant->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/donor/grant')
        ->where('shares', ['budget' => false, 'spending' => false, 'commitments' => false, 'progress' => false, 'milestones' => false, 'tasks' => false])
        ->has('projects', 1)
        ->where('projects.0.code', 'WASH')
        ->where('projects.0.allocatedMinor', 6000000)
        ->where('projects.0.spending', null)
        ->where('projects.0.commitments', null)
        ->where('projects.0.budget', null)
        ->where('projects.0.progress', null)
        ->where('projects.0.milestones', null));
    $response->assertDontSee('Hand pumps')->assertDontSee('USAID')->assertDontSee('Internal:');

    $this->actingAs($this->staff);
    portalShare($grant, ['portal_spending' => true, 'portal_progress' => true]);
    $this->actingAs($contact);

    // Spending and progress, but not commitments or milestones: each has its own switch.
    $response = $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.spending.paidMinor', 50000)
        ->missing('projects.0.spending.committedMinor')
        ->has('projects.0.spending.lines', 1)
        ->where('projects.0.spending.lines.0.description', 'Hand pumps')
        ->where('projects.0.commitments', null)
        ->where('projects.0.progress.status', 'planning')
        ->missing('projects.0.progress.milestones')
        ->where('projects.0.milestones', null)
        ->where('projects.0.budget', null));
    $response->assertDontSee('Pipes on order');

    $this->actingAs($this->staff);
    portalShare($grant, ['portal_commitments' => true, 'portal_milestones' => true]);
    $this->actingAs($contact);

    // Commitments as totals by category: never what each one is.
    $response = $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.commitments.committedMinor', 50000)
        ->has('projects.0.commitments.categories', 1)
        ->where('projects.0.milestones', [])
        ->where('projects.0.spending', null));

    $response->assertDontSee('Secret Supplier')->assertDontSee('SECRET-REF')->assertDontSee('Pipes on order')
        ->assertDontSee('Paid from core funds')->assertDontSee('Another donor pays')
        ->assertDontSee('Internal:')->assertDontSee('FOOD')->assertDontSee($this->staff->email);
});

it('gives documents only once the report reaches the funder', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $report = portalReport($grant);
    $this->post(portalUrl("grants/{$grant->id}/reports/{$report->id}/documents"), ['document' => UploadedFile::fake()->create('script.exe', 10)])->assertSessionHasErrors('document');
    $this->post(portalUrl("grants/{$grant->id}/reports/{$report->id}/documents"), ['document' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => $report->documents()->sole());
    Storage::disk('local')->assertExists($document->path);
    $staffUrl = portalUrl("grants/{$grant->id}/reports/{$report->id}/documents/{$document->id}");
    $this->get($staffUrl)->assertOk();

    $otherGrant = portalGrant($this->asTenant('acme', fn () => Funder::query()->sole()), 'ECHO/9');
    $this->get(portalUrl("grants/{$otherGrant->id}/reports/{$report->id}/documents/{$document->id}"))->assertNotFound();
    $staff = $this->staff;
    $contact = portalContact($grant);
    $url = portalUrl("donor/grants/{$grant->id}/reports/{$report->id}/documents/{$document->id}");

    $this->get($url)->assertNotFound();
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page->has('reports.0.documents', 0));

    $this->actingAs($staff);
    portalSubmit($report);
    $this->delete($staffUrl)->assertSessionHasErrors('document');
    $this->actingAs($contact);

    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page->where('reports.0.documents.0.name', 'report.pdf'));
    $this->get($url)->assertOk();
    // Through another shared grant, the same file is not reachable.
    $this->actingAs($staff);
    portalShare($otherGrant);
    $this->actingAs($contact);
    $this->get(portalUrl("donor/grants/{$otherGrant->id}/reports/{$report->id}/documents/{$document->id}"))->assertNotFound();
    expect($this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'grant.report_document_downloaded')->count()))->toBe(1);
});

it('removes a report added by mistake with its draft documents', function () {
    $grant = portalGrant(portalFunder());
    $report = portalReport($grant);
    $this->post(portalUrl("grants/{$grant->id}/reports/{$report->id}/documents"), ['document' => UploadedFile::fake()->create('draft.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => $report->documents()->sole());

    $this->delete(portalUrl("grants/{$grant->id}/reports/{$report->id}"))->assertSessionHasNoErrors()->assertRedirect();

    Storage::disk('local')->assertMissing($document->path);
    expect($this->asTenant('acme', fn () => GrantReport::query()->count()))->toBe(0);
});

it('lets a funder accept a submitted report or ask for a revision', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $report = portalReport($grant);
    $staff = $this->staff;
    $contact = portalContact($grant);
    $decision = portalUrl("donor/grants/{$grant->id}/reports/{$report->id}/decision");

    $this->post($decision, ['status' => 'accepted'])->assertSessionHasErrors('status');

    $this->actingAs($staff);
    portalSubmit($report);
    expect($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $contact->id)->value('url')))
        ->toContain("/donor/grants/{$grant->id}");
    $this->actingAs($contact);

    $this->post($decision, ['status' => 'due'])->assertSessionHasErrors('status');
    $this->post($decision, ['status' => 'revision_requested'])->assertSessionHasErrors('reason');
    $this->post($decision, ['status' => 'revision_requested', 'reason' => 'Add the water tests'])->assertSessionHasNoErrors()->assertRedirect();
    expect($report->fresh()?->status->value)->toBe('revision_requested');
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('messages.0.body', 'Revision requested on First report: Add the water tests')
        ->where('messages.0.fromFunder', true));
    $this->post($decision, ['status' => 'accepted'])->assertSessionHasErrors('status');

    $this->actingAs($staff);
    expect($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $staff->id)->where('kind', UserNotification::MESSAGE)->count()))->toBe(1);
    portalSubmit($report->fresh() ?? $report);
    $this->actingAs($contact);
    $this->post($decision, ['status' => 'accepted'])->assertSessionHasNoErrors()->assertRedirect();

    expect($report->fresh()?->status->value)->toBe('accepted')
        ->and($this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'grant.report_accepted')->where('user_id', $contact->id)->count()))->toBe(1);
});

it('carries a conversation both ways and alerts the other side', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $staff = $this->staff;
    $contact = portalContact($grant);

    $this->post(portalUrl("donor/grants/{$grant->id}/messages"), ['body' => ''])->assertSessionHasErrors('body');
    $this->post(portalUrl("donor/grants/{$grant->id}/messages"), ['body' => 'When is the next report?'])->assertSessionHasNoErrors()->assertRedirect();

    $this->actingAs($staff);
    expect($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $staff->id)->where('kind', UserNotification::MESSAGE)->sole()->title))->toContain('Donor Officer');
    $this->post(portalUrl("grants/{$grant->id}/messages"), ['body' => 'In December.'])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(portalUrl("grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('portal.messages.0.fromFunder', true)
        ->where('portal.messages.1.body', 'In December.'));

    expect($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $contact->id)->where('kind', UserNotification::MESSAGE)->count()))->toBe(1);
    $this->actingAs($contact);
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page->has('messages', 2));
});

it('ends access when the contact is removed', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);
    $staff = $this->staff;
    $contact = portalContact($grant);
    $this->get(portalUrl('donor'))->assertOk();
    $record = $this->asTenant('acme', fn () => FunderContact::query()->sole());

    $this->actingAs($staff);
    $this->delete(portalUrl("grants/{$grant->id}/portal/contacts/{$record->id}"))->assertSessionHasNoErrors()->assertRedirect();

    // Their place on the platform stays until an admin removes it there; here they are no one's contact.
    expect($this->asTenant('acme', fn () => FunderContact::query()->where('user_id', $contact->id)->exists()))->toBeFalse();
    $this->actingAs($contact);
    $this->get(portalUrl('donor'))->assertNotFound();
});

it('refuses to invite someone already on the team, and keeps the team out of confinement', function () {
    $grant = portalGrant(portalFunder());
    portalShare($grant);

    $this->post(portalUrl("grants/{$grant->id}/portal/contacts"), ['email' => $this->staff->email])->assertSessionHasErrors('email');

    // A contact later given a role works as staff, and keeps that place when
    // they stop acting for the funder.
    $staff = $this->staff;
    $contact = portalContact($grant);
    $this->member('acme', ['grants.view'], user: $contact);
    $this->get(portalUrl('grants'))->assertOk();
    $this->get(portalUrl('donor'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('donorPortal.confined', false));

    $record = $this->asTenant('acme', fn () => FunderContact::query()->sole());
    $this->actingAs($staff);
    $this->delete(portalUrl("grants/{$grant->id}/portal/contacts/{$record->id}"))->assertRedirect();
    expect(OrganizationMembership::query()->where('user_id', $contact->id)->exists())->toBeTrue();
});

it('needs grants.manage for the staff side', function () {
    $grant = portalGrant(portalFunder());
    $this->actingAsMember('acme', ['grants.view']);

    $this->put(portalUrl("grants/{$grant->id}/portal"), ['portal_enabled' => true, 'portal_budget' => true, 'portal_spending' => true, 'portal_progress' => true])->assertForbidden();
    $this->post(portalUrl("grants/{$grant->id}/portal/contacts"), ['email' => 'a@b.example'])->assertForbidden();
    $this->post(portalUrl("grants/{$grant->id}/messages"), ['body' => 'Hello'])->assertForbidden();
    expect($grant->fresh()?->portal_enabled)->toBeFalse();
});

it('shares the approved budget by category, and never a draft', function () {
    $grant = portalGrant(portalFunder());
    $project = portalProject($grant);
    $staff = $this->staff;
    $owner = $this->actingAsMember('acme', [], owner: true);
    $budget = fn (string $path) => portalUrl("projects/{$project->id}/budget/{$path}");
    $this->post($budget('lines'), ['category' => 'equipment', 'amount' => '5000'])->assertSessionHasNoErrors();
    $this->post($budget('lines'), ['category' => 'travel', 'amount' => '1000'])->assertSessionHasNoErrors();

    $this->actingAs($staff);
    portalShare($grant, ['portal_budget' => true]);
    $contact = portalContact($grant);
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page->where('projects.0.budget', null));

    $version = $this->asTenant('acme', fn () => Budgets::working($project));
    $this->actingAs($owner);
    $this->post($budget("versions/{$version?->id}/submit"))->assertSessionHasNoErrors();
    // Whoever submits a budget does not approve it.
    $this->actingAsMember('acme', [], owner: true);
    $this->post($budget("versions/{$version?->id}/approve"))->assertSessionHasNoErrors()->assertRedirect();

    $this->actingAs($contact);
    $this->get(portalUrl("donor/grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('projects.0.budget.categories', 2)
        ->where('projects.0.budget.categories', fn ($lines) => collect($lines)->pluck('amountMinor', 'category')->all() === ['Equipment' => 500000, 'Travel' => 100000]));
});
