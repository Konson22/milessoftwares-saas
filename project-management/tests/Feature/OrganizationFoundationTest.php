<?php

use App\Actions\Organizations;
use App\Actions\OrgUnits;
use App\Audit\Audit;
use App\Enums\FunderKind;
use App\Models\AuditEvent;
use App\Models\Funder;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrgUnit;
use App\Models\Partnership;
use App\Models\User;
use App\Support\Modules;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What an organization sets up for itself: the parts of the product it uses, how
| it is arranged, and the log of all of it.
*/

function foundationOrganization(string $name = 'Own Work', ?User $owner = null): array
{
    $slug = Str::slug($name);
    $owner = test()->member($slug, owner: true, user: $owner);

    return [test()->organization($slug), $owner];
}

/**
 * @param  list<string>  $permissions
 */
function foundationMember(Organization $organization, array $permissions = []): User
{
    return test()->member($organization->slug, $permissions);
}

/**
 * A page of the organization's Projects.
 */
function foundationUrl(string $path, string $slug = 'own-work'): string
{
    return test()->organizationUrl($slug, $path);
}

function inOrganization(Organization $organization, callable $work): mixed
{
    return app(CurrentOrganization::class)->run($organization, $work);
}

it('has every module on until it is switched off', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);

    $this->get(foundationUrl('projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organization.modules', Modules::keys()));
    foreach (['grants', 'funders', 'procurement', 'petty-cash', 'advances'] as $path) {
        $this->get(foundationUrl($path))->assertOk();
    }
});

it('refuses the pages of a module that is off, even to the owner, and keeps what is in it', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);
    $this->get(foundationUrl('projects'))->assertOk();

    foreach ([
        Modules::GRANTS => ['grants', 'funders', 'donor'],
        Modules::PROCUREMENT => ['procurement'],
        Modules::ACCOUNTING => ['petty-cash', 'advances'],
    ] as $module => $paths) {
        $this->put(foundationUrl("admin/settings/modules/{$module}"), ['enabled' => '0'])->assertSessionHasNoErrors();
        expect($organization->refresh()->hasModule($module))->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'organization.module_disabled')->where('organization_id', $organization->id)->whereJsonContains('properties->module', $module)->exists())->toBeTrue();

        foreach ($paths as $path) {
            $this->get(foundationUrl($path))->assertForbidden();
        }

        $this->put(foundationUrl("admin/settings/modules/{$module}"), ['enabled' => '1'])->assertSessionHasNoErrors();
        foreach (array_diff($paths, ['donor']) as $path) {
            $this->get(foundationUrl($path))->assertOk();
        }
    }

    expect($organization->refresh()->disabled_modules)->toBeNull();
    $this->put(foundationUrl('admin/settings/modules/payroll'), ['enabled' => '0'])->assertNotFound();
});

it('leaves grants out of search while they are off', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);
    inOrganization($organization, fn () => Funder::query()->create(['name' => 'Findable Fund', 'kind' => FunderKind::cases()[0]->value]));
    $this->get(foundationUrl('search?q=Findable'))->assertInertia(fn (Assert $page) => $page->has('groups', 1));

    Organizations::setModule($organization, Modules::GRANTS, false, $owner);

    $this->get(foundationUrl('search?q=Findable'))->assertInertia(fn (Assert $page) => $page->has('groups', 0));
});

it('keeps grants on while a donor follows them', function () {
    [$organization, $owner] = foundationOrganization();
    [$donor, $donorOwner] = foundationOrganization('The Donor');
    $partnership = new Partnership(['label' => 'Implementer', 'name' => 'Own Work', 'email' => 'x@example.org', 'currency' => 'USD', 'timezone' => 'UTC']);
    $partnership->forceFill(['donor_organization_id' => $donor->id, 'beneficiary_organization_id' => $organization->id, 'invited_by' => $donorOwner->id, 'expires_at' => now()->addDay()])->save();
    $this->actingAs($owner);
    $this->get(foundationUrl('projects'))->assertOk();

    $this->from(foundationUrl('admin/settings'))->put(foundationUrl('admin/settings/modules/grants'), ['enabled' => '0'])
        ->assertSessionHasErrors('module');
    expect($organization->refresh()->hasModule(Modules::GRANTS))->toBeTrue();
});

it('needs organization.manage to change modules', function () {
    [$organization] = foundationOrganization();
    $this->actingAs(foundationMember($organization));
    $this->get(foundationUrl('projects'));

    $this->put(foundationUrl('admin/settings/modules/grants'), ['enabled' => '0'])->assertForbidden();
    expect($organization->refresh()->hasModule(Modules::GRANTS))->toBeTrue();
});

it('arranges units, one inside another, and never in a circle', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);
    $this->get(foundationUrl('admin/org-units'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/admin/org-units')->has('units', 0));

    $this->post(foundationUrl('admin/org-units'), ['kind' => 'department', 'name' => 'Programmes'])->assertSessionHasNoErrors();
    $programmes = OrgUnit::query()->where('name', 'Programmes')->firstOrFail();
    $this->post(foundationUrl('admin/org-units'), ['kind' => 'team', 'name' => 'Water', 'parent_id' => $programmes->id])->assertSessionHasNoErrors();
    $water = OrgUnit::query()->where('name', 'Water')->firstOrFail();

    $this->from(foundationUrl('admin/org-units'))->put(foundationUrl("admin/org-units/{$programmes->id}"), ['kind' => 'department', 'name' => 'Programmes', 'parent_id' => $water->id])
        ->assertSessionHasErrors('parent_id');
    $this->from(foundationUrl('admin/org-units'))->put(foundationUrl("admin/org-units/{$programmes->id}"), ['kind' => 'department', 'name' => 'Programmes', 'parent_id' => $programmes->id])
        ->assertSessionHasErrors('parent_id');

    $this->put(foundationUrl("admin/org-units/{$water->id}"), ['kind' => 'team', 'name' => 'Water and sanitation', 'parent_id' => $programmes->id])->assertSessionHasNoErrors();
    $event = AuditEvent::query()->where('action', 'org_unit.updated')->firstOrFail();
    expect($event->properties)->toBe(['before' => ['name' => 'Water'], 'after' => ['name' => 'Water and sanitation']]);
});

it('archives a unit only when it is empty, and restores it', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);
    $this->get(foundationUrl('projects'))->assertOk();
    $parent = OrgUnits::create(['kind' => 'department', 'name' => 'Finance'], $owner);
    $child = OrgUnits::create(['kind' => 'team', 'name' => 'Payables', 'parent_id' => $parent->id], $owner);
    $member = foundationMember($organization);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->firstOrFail();

    $this->put(foundationUrl("admin/members/{$membership->id}/unit"), ['org_unit_id' => $child->id])->assertSessionHasNoErrors();
    expect($membership->refresh()->org_unit_id)->toBe($child->id);

    $archive = fn (OrgUnit $unit) => $this->from(foundationUrl('admin/org-units'))->post(foundationUrl("admin/org-units/{$unit->id}/archive"));
    $archive($parent)->assertSessionHasErrors('unit');
    $archive($child)->assertSessionHasErrors('unit');

    $this->put(foundationUrl("admin/members/{$membership->id}/unit"), ['org_unit_id' => null])->assertSessionHasNoErrors();
    $archive($child)->assertSessionHasNoErrors();
    $archive($parent)->assertSessionHasNoErrors();
    expect($child->refresh()->archived_at)->not->toBeNull();

    // An archived unit takes no new members, and comes back top down.
    $this->from(foundationUrl('admin/members'))->put(foundationUrl("admin/members/{$membership->id}/unit"), ['org_unit_id' => $child->id])->assertSessionHasErrors('org_unit_id');
    $this->from(foundationUrl('admin/org-units'))->post(foundationUrl("admin/org-units/{$child->id}/restore"))->assertSessionHasErrors('unit');
    $this->post(foundationUrl("admin/org-units/{$parent->id}/restore"))->assertSessionHasNoErrors();
    $this->post(foundationUrl("admin/org-units/{$child->id}/restore"))->assertSessionHasNoErrors();
});

it('keeps units to their organization', function () {
    [$first, $firstOwner] = foundationOrganization('First');
    [$second, $secondOwner] = foundationOrganization('Second');
    $theirs = inOrganization($second, fn () => OrgUnits::create(['kind' => 'team', 'name' => 'Theirs'], $secondOwner));
    $member = foundationMember($first);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($firstOwner);
    $this->get(foundationUrl('admin/org-units', 'first'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('units', 0));
    $this->put(foundationUrl("admin/org-units/{$theirs->id}", 'first'), ['kind' => 'team', 'name' => 'Mine now'])->assertNotFound();
    $this->post(foundationUrl("admin/org-units/{$theirs->id}/archive", 'first'))->assertNotFound();
    $this->put(foundationUrl("admin/members/{$membership->id}/unit", 'first'), ['org_unit_id' => $theirs->id])->assertStatus(422);
    $this->from(foundationUrl('admin/org-units', 'first'))->post(foundationUrl('admin/org-units', 'first'), ['kind' => 'team', 'name' => 'Inside theirs', 'parent_id' => $theirs->id])
        ->assertSessionHasErrors('parent_id');

    expect($theirs->fresh())->not->toBeNull();
    expect($membership->refresh()->org_unit_id)->toBeNull();
});

it('has the database refuse a unit of another organization', function () {
    [$first] = foundationOrganization('First');
    [$second, $secondOwner] = foundationOrganization('Second');
    $theirs = inOrganization($second, fn () => OrgUnits::create(['kind' => 'team', 'name' => 'Theirs'], $secondOwner));
    $member = foundationMember($first);

    expect(fn () => DB::transaction(fn () => OrganizationMembership::query()->where('user_id', $member->id)->update(['org_unit_id' => $theirs->id])))
        ->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite' && ! DB::scalar('PRAGMA foreign_keys'), 'Needs foreign keys on');

it('records only what changed, and nothing when nothing did', function () {
    [$organization, $owner] = foundationOrganization();
    $this->actingAs($owner);

    expect(inOrganization($organization, fn () => Audit::change('x.same', $organization, ['a' => 1], ['a' => 1])))->toBeNull();

    $event = inOrganization($organization, fn () => Audit::change('x.changed', $organization, ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3], ['why' => 'test']));
    expect($event->properties)->toBe(['why' => 'test', 'before' => ['b' => 2], 'after' => ['b' => 3]]);
});

it('never writes secrets to the audit log', function () {
    [$organization] = foundationOrganization();

    $event = inOrganization($organization, fn () => Audit::record('x.secret', null, [
        'name' => 'kept',
        'password' => 'hunter2',
        'nested' => ['api_key' => 'abc', 'recovery_codes' => ['a', 'b'], 'kept' => 1],
        'after' => ['two_factor_secret' => 'xyz'],
    ]));

    expect($event->properties)->toBe([
        'name' => 'kept',
        'password' => '[hidden]',
        'nested' => ['api_key' => '[hidden]', 'recovery_codes' => '[hidden]', 'kept' => 1],
        'after' => ['two_factor_secret' => '[hidden]'],
    ]);
});

it('has the database refuse changing or deleting the audit log, but lets a removed user go', function () {
    [$organization, $owner] = foundationOrganization();
    $event = inOrganization($organization, fn () => Audit::record('x.kept', null, ['a' => 1], actor: $owner));

    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'x.changed'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->delete()))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->update(['user_id' => null, 'action' => 'x.changed'])))
        ->toThrow(QueryException::class, 'append-only');

    DB::table('audit_events')->where('id', $event->id)->update(['user_id' => null]);
    expect(DB::table('audit_events')->where('id', $event->id)->value('action'))->toBe('x.kept');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');
