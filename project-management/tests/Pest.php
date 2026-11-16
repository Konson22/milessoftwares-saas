<?php

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Notifications\InvitedToProject;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Catalog roles and the organization
|--------------------------------------------------------------------------
*/

/**
 * A role's permissions as the catalog defines them.
 *
 * @return list<string>
 */
function rolePermissions(string $role): array
{
    static $catalog = null;
    $catalog ??= json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);

    foreach ($catalog['roles'] as $definition) {
        if ($definition['key'] === $role) {
            return $definition['permissions'];
        }
    }

    throw new InvalidArgumentException("No role {$role} in the catalog.");
}

/**
 * Signs in as someone holding a catalog role (a new person unless given).
 */
function actingAsRole(string $role, ?User $user = null, string $slug = 'acme', string $name = 'Test Member'): User
{
    $user ??= User::factory()->create(['name' => $name]);
    test()->actingAsMember($slug, rolePermissions($role), user: $user);

    return $user;
}

/**
 * Within the organization.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function inAcme(callable $callback, string $slug = 'acme'): mixed
{
    return test()->asTenant($slug, $callback);
}

/*
| Projects shared with other organizations (ADR-0014), for the collaboration,
| partner report and request tests.
*/

const COLLAB_MANAGER = ['projects.view', 'projects.partners.manage'];

/**
 * The owner of $slug starts a project there (signed in as them after).
 */
function collabProject(string $slug = 'acme', string $code = 'WASH'): Project
{
    test()->actingAsMember($slug, [], owner: true);
    test()->post(test()->organizationUrl($slug, 'projects'), [
        'code' => $code, 'name' => 'Clean water', 'start_on' => '2026-11-01', 'due_on' => '2027-10-31', 'budget' => '50000',
    ])->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl($slug, 'projects/'.test()->asTenant($slug, fn () => Project::query()->where('code', $code)->firstOrFail())->id.'/milestones'), ['name' => 'Wells drilled', 'due_on' => '2027-03-01'])->assertSessionHasNoErrors();

    return test()->asTenant($slug, fn () => Project::query()->where('code', $code)->firstOrFail());
}

/**
 * The owner of the project's organization invites an organization on Tatook.
 *
 * @param  list<string>  $capabilities
 */
function inviteToProject(Project $project, string $invited, string $relationship = 'implementer', array $capabilities = ['view'], ?string $parent = null, string $owner = 'acme'): Invitation
{
    test()->actingAsMember($owner, [], owner: true);
    test()->post(test()->organizationUrl($owner, "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => test()->organization($invited)->id,
        'relationship' => $relationship,
        'capabilities' => $capabilities,
        'parent_participant_id' => $parent,
        'message' => 'Join us on the water project.',
    ])->assertSessionHasNoErrors();

    // Time stands still in these tests: the one not yet answered, not the latest.
    return Invitation::query()->where('invited_organization_id', test()->organization($invited)->id)->unanswered()->sole();
}

/**
 * Someone who manages $slug's collaboration accepts there.
 */
function acceptProjectInvitation(Invitation $invitation, string $slug, ?User $user = null): Participant
{
    test()->actingAsMember($slug, COLLAB_MANAGER, user: $user);
    test()->post(test()->organizationUrl($slug, "shared-projects/invitations/{$invitation->id}/accept"))->assertSessionHasNoErrors()->assertRedirect();

    return Participant::query()->where('invitation_id', $invitation->id)->firstOrFail();
}

/**
 * The emailed link, by address.
 */
function projectInvitationLink(string $email): string
{
    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitedToProject::class, function (InvitedToProject $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $url = $notification->url;
        }

        return true;
    });

    return (string) $url;
}
