<?php

use App\Logbook\Models\AccessGrant;
use Illuminate\Support\Facades\Cache;

/*
 * A person reaches a logbook only as its member (contributor, reviewer or
 * viewer), and only with a Logbook role for its department. Managers of
 * the department reach it without being members.
 */

beforeEach(function () {
    $this->team = team();
    $this->maintenance = $this->platform()->department('acme', 'Maintenance');
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Generators', ['department_id' => $this->maintenance, 'restricted' => true]);
    $this->sheet = publishedSheet($this->logbook, 'GEN');
});

function addToLogbook(string $logbookId, string $userId, string $role)
{
    return test()->post(p("logbooks/{$logbookId}/access"), ['user_id' => $userId, 'role' => $role]);
}

it('keeps a logbook from everyone who is not a member, whatever their role', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 0));
    writeEntry($this->sheet)->assertNotFound();

    as_($this->team['manager'], 'logbook_manager');
    $this->get(p("logbooks/{$this->logbook->id}"))->assertOk()->assertInertia(fn ($page) => $page->has('grants', 0));
    addToLogbook($this->logbook->id, $this->team['writer']->id, 'contributor')->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 1));
    writeEntry($this->sheet)->assertSessionHasNoErrors();
});

it('adds only people whose Logbook role allows it in the logbook\'s department', function () {
    // A reviewer cannot be a contributor.
    addToLogbook($this->logbook->id, $this->team['reviewer']->id, 'contributor')->assertSessionHasErrors('role');

    // A contributor for another department is refused.
    $security = $this->platform()->department('acme', 'Security');
    $this->platform()->grant('acme', (string) $this->team['other']->id, [['permissions' => rolePermissions('logbook_contributor'), 'scope' => ['scope_type' => 'department', 'department_id' => $security], 'role' => 'logbook_contributor']]);
    Cache::flush();
    addToLogbook($this->logbook->id, $this->team['other']->id, 'contributor')->assertSessionHasErrors('user_id');

    // A contributor for this department is welcome.
    $this->platform()->grant('acme', (string) $this->team['other']->id, [['permissions' => rolePermissions('logbook_contributor'), 'scope' => ['scope_type' => 'department', 'department_id' => $this->maintenance], 'role' => 'logbook_contributor']]);
    Cache::flush();
    addToLogbook($this->logbook->id, $this->team['other']->id, 'contributor')->assertSessionHasNoErrors();
});

it('makes the people who reached open logbooks their members, once', function () {
    // An open logbook from before members were required: nobody listed.
    as_($this->team['manager'], 'logbook_manager');
    $old = logbook('Office', ['restricted' => true]);
    inTenant(fn () => $old->forceFill(['restricted' => false])->save());

    $organization = $this->platform()->organization('acme')['id'];
    $this->platform()->applicationMembers['acme'] = collect($this->team)->map(fn ($u) => ['sub' => (string) $u->id, 'name' => $u->name, 'roles' => []])->values()->all();
    $this->artisan('logbook:members-from-open-logbooks', ['--organization' => $organization])->assertSuccessful();
    $roles = inTenant(fn () => AccessGrant::query()->where('logbook_id', $old->id)->with('user')->get()->mapWithKeys(fn ($g) => [$g->user->name => $g->role])->sort()->all());
    // Managers reach it anyway; everyone else in the first role theirs allows.
    expect($roles)->toMatchArray(['Writer' => 'contributor', 'Other' => 'contributor', 'Reviewer' => 'reviewer', 'Viewer' => 'viewer'])
        ->and($roles)->not->toHaveKey('Manager');

    // Again: nothing new.
    $before = inTenant(fn () => AccessGrant::query()->count());
    $this->artisan('logbook:members-from-open-logbooks', ['--organization' => $organization])->assertSuccessful();
    expect(inTenant(fn () => AccessGrant::query()->count()))->toBe($before);
    // The restricted one kept its (empty) list.
    expect(inTenant(fn () => AccessGrant::query()->where('logbook_id', $this->logbook->id)->count()))->toBe(0);
});
