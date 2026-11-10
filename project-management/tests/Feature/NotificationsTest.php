<?php

use App\Actions\Inbox;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| In-app notifications: mentions, assignments, being added to a task, and
| comments on your tasks. Only for people who can open the task, never about
| what you did yourself, and each person's are their own.
*/

const INBOX_WORKER = ['projects.view', 'projects.tasks.manage'];

/**
 * A member with a person in the directory, as if they had opened the app.
 *
 * @param  list<string>  $permissions
 * @return array{0: User, 1: Person}
 */
function inboxMember(string $name, array $permissions = INBOX_WORKER, string $slug = 'acme'): array
{
    $user = test()->member($slug, $permissions, user: User::factory()->create(['name' => $name]));
    $person = test()->asTenant($slug, function () use ($user) {
        $person = new Person(['name' => $user->name, 'email' => $user->email]);
        $person->forceFill(['user_id' => $user->id])->save();

        return $person;
    });

    return [$user, $person];
}

function inboxUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function inboxTask(array $details = []): Task
{
    test()->post(inboxUrl('projects/'.test()->project->id.'/tasks'), ['title' => 'Fix the pump', 'priority' => 'high', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->latest('created_at')->orderByDesc('id')->firstOrFail());
}

function commentOn(Task $task, string $body): void
{
    test()->post(inboxUrl('projects/'.test()->project->id."/tasks/{$task->id}/comments"), ['body' => $body])->assertSessionHasNoErrors();
}

/**
 * @return array<int, string>
 */
function kindsFor(User $user): array
{
    return test()->asTenant('acme', fn () => UserNotification::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('kind')->all());
}

beforeEach(function () {
    [$this->author, $this->authorPerson] = inboxMember('Achol Deng');
    [$this->mary, $this->maryPerson] = inboxMember('Mary Akech');
    [$this->james, $this->jamesPerson] = inboxMember('James Wani');
    $this->actingAs($this->author);
    $this->project = $this->asTenant('acme', fn () => Project::query()->create([
        'code' => 'WASH', 'name' => 'Water', 'status' => Project::ACTIVE, 'currency' => 'USD', 'created_by' => $this->author->id,
    ]));
});

it('tells someone mentioned in a comment, with the comment', function () {
    $task = inboxTask();
    commentOn($task, 'Please check this @Mary Akech, thanks');

    expect(kindsFor($this->mary))->toBe([UserNotification::MENTIONED])
        ->and(kindsFor($this->james))->toBe([]);

    $notification = $this->asTenant('acme', fn () => UserNotification::query()->sole());
    expect($notification->title)->toBe("Achol Deng mentioned you on {$task->number}")
        ->and($notification->excerpt)->toBe('Please check this @Mary Akech, thanks')
        ->and($notification->read_at)->toBeNull();
});

it('finds mentions in any case, the longest name, and not in email addresses', function () {
    [, $mary2] = inboxMember('Mary');

    $find = fn (string $text) => $this->asTenant('acme', fn () => Inbox::mentionedIn($text)->pluck('name')->all());

    expect($find('@mary akech look'))->toBe(['Mary Akech'])
        ->and($find('(@Mary) and @James Wani.'))->toEqualCanonicalizing(['Mary', 'James Wani'])
        ->and($find('@Mary Akech and @Mary'))->toEqualCanonicalizing(['Mary Akech', 'Mary'])
        ->and($find('write to a@mary.org'))->toBe([])
        ->and($find('@Maryam hello'))->toBe([])
        ->and($find('no mention of Mary Akech'))->toBe([]);
});

it('tells someone once when they are mentioned and on the task, and never the author', function () {
    $task = inboxTask(['assignee_id' => $this->maryPerson->id]);
    $this->post(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}/collaborators"), ['person_id' => $this->jamesPerson->id])->assertSessionHasNoErrors();
    expect(kindsFor($this->mary))->toBe([UserNotification::ASSIGNED])
        ->and(kindsFor($this->james))->toBe([UserNotification::ADDED]);

    commentOn($task, 'Over to you @Mary Akech, and me: @Achol Deng');

    // Mary is both the lead and mentioned: one, as a mention. James hears of a comment.
    expect(kindsFor($this->mary))->toBe([UserNotification::ASSIGNED, UserNotification::MENTIONED])
        ->and(kindsFor($this->james))->toBe([UserNotification::ADDED, UserNotification::COMMENTED])
        ->and(kindsFor($this->author))->toBe([]);
});

it('tells someone given a task, on a change of lead only, and not when they give it to themselves', function () {
    $task = inboxTask(['assignee_id' => $this->authorPerson->id]);
    expect(kindsFor($this->author))->toBe([]);

    $form = ['title' => 'Fix the pump', 'priority' => 'high'];
    $this->put(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}"), [...$form, 'assignee_id' => $this->maryPerson->id])->assertSessionHasNoErrors();
    $this->put(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}"), [...$form, 'assignee_id' => $this->maryPerson->id, 'description' => 'More'])->assertSessionHasNoErrors();
    expect(kindsFor($this->mary))->toBe([UserNotification::ASSIGNED]);

    $this->post(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}/subtasks"), ['title' => 'Sub', 'assignee_id' => $this->jamesPerson->id])->assertSessionHasNoErrors();
    expect(kindsFor($this->james))->toBe([UserNotification::ASSIGNED]);
});

it('adds a collaborator notification only the first time', function () {
    $task = inboxTask();
    $url = inboxUrl("projects/{$this->project->id}/tasks/{$task->id}/collaborators");

    $this->post($url, ['person_id' => $this->maryPerson->id])->assertSessionHasNoErrors();
    $this->post($url, ['person_id' => $this->maryPerson->id])->assertSessionHasNoErrors();

    expect(kindsFor($this->mary))->toBe([UserNotification::ADDED]);
});

it('tells nobody who could not open the task', function () {
    [$cannotView] = inboxMember('No View', ['grants.view']);
    [$suspendedMember, $suspendedPerson] = inboxMember('Sus Member');
    [$inactiveUser] = inboxMember('Inactive User');
    $handAdded = $this->asTenant('acme', fn () => Person::query()->create(['name' => 'Hand Added']));
    [$foreign] = inboxMember('Foreign Person', INBOX_WORKER, 'other');

    $organization = Organization::query()->where('slug', 'acme')->sole();
    OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $suspendedMember->id)->update(['status' => OrganizationMembership::SUSPENDED]);
    $inactiveUser->forceFill(['status' => User::SUSPENDED])->save();

    $task = inboxTask();
    commentOn($task, '@No View @Sus Member @Inactive User @Hand Added @Foreign Person');

    $this->asTenant('acme', fn () => expect(UserNotification::query()->count())->toBe(0));
    $this->asTenant('other', fn () => expect(UserNotification::query()->count())->toBe(0));

    // Nor are they offered when typing @.
    $this->get(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('mentionable', ['Achol Deng', 'James Wani', 'Mary Akech']));

    expect($suspendedPerson->name)->toBe('Sus Member')->and($handAdded->user_id)->toBeNull()->and($cannotView->name)->toBe('No View')->and($foreign->name)->toBe('Foreign Person');
});

it('sends no email', function () {
    Mail::fake();
    Notification::fake();

    $task = inboxTask(['assignee_id' => $this->maryPerson->id]);
    commentOn($task, '@James Wani look');

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    expect(kindsFor($this->james))->toHaveCount(1);
});

it('counts unread on every page, opens a notification on its task, and marks all read', function () {
    $task = inboxTask();
    commentOn($task, '@Mary Akech one');
    commentOn($task, '@Mary Akech two');

    $this->actingAs($this->mary);
    $this->get(inboxUrl('projects'))->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 2));
    $this->get(inboxUrl('notifications'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/notifications')
        ->has('notifications', 2)
        ->where('notifications.0.excerpt', '@Mary Akech two')
        ->where('notifications.0.read', false));

    $first = $this->asTenant('acme', fn () => UserNotification::query()->where('excerpt', '@Mary Akech one')->sole());
    $this->post(inboxUrl("notifications/{$first->id}/open"))->assertRedirect(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}"));
    $this->get(inboxUrl('projects'))->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 1));

    $this->post(inboxUrl('notifications/read-all'));
    $this->get(inboxUrl('projects'))->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 0));
});

it('keeps each person\'s notifications their own, and each organization\'s to itself', function () {
    $task = inboxTask();
    commentOn($task, '@Mary Akech yours');
    $notification = $this->asTenant('acme', fn () => UserNotification::query()->sole());

    // Someone else in the organization.
    $this->actingAs($this->james);
    $this->get(inboxUrl('notifications'))->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    $this->get(inboxUrl('projects'))->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 0));
    $this->post(inboxUrl("notifications/{$notification->id}/open"))->assertNotFound();
    $this->post(inboxUrl('notifications/read-all'));
    $this->asTenant('acme', fn () => expect(UserNotification::query()->sole()->read_at)->toBeNull());

    // The same person, in another organization they belong to.
    $this->member('other', INBOX_WORKER, user: $this->mary);
    $this->actingAs($this->mary);
    $this->get(inboxUrl('notifications', 'other'))->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    $this->get(inboxUrl('projects', 'other'))->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 0));
    $this->post(inboxUrl("notifications/{$notification->id}/open", 'other'))->assertNotFound();

    // Someone who is not a member at all.
    $this->actingAs(User::factory()->create());
    $this->get(inboxUrl('notifications'))->assertForbidden();
});

it('needs someone signed in', function () {
    auth()->logout();
    $this->get(inboxUrl('notifications'))->assertRedirect();
});

it('offers the people who can be mentioned on the task page', function () {
    $task = inboxTask();

    $this->get(inboxUrl("projects/{$this->project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('mentionable', ['Achol Deng', 'James Wani', 'Mary Akech']));
});
