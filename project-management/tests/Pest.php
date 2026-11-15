<?php

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Result;
use App\Mel\Models\Submission;
use App\Models\Project;
use App\Models\User;
use App\Notifications\InvitedToProject;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Monitoring & evaluation (tests/Feature/Mel)
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
 * A URL of M&E in an organization's workspace: mel('programmes') is
 * http://acme.<root>/projects/mel/programmes.
 */
function mel(string $path, string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, rtrim('mel/'.ltrim($path, '/'), '/'));
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

/**
 * A programme for 2026 with two units, quarterly periods (due 15 days
 * after), a goal, an outcome and an output, set up through the pages by
 * whoever is signed in (an M&E manager).
 *
 * @return array{programme: Programme, units: array<string, ProgrammeUnit>, periods: array<string, ReportingPeriod>, output: Result}
 */
function setUpProgramme(string $code = 'WASH', string $slug = 'acme'): array
{
    test()->post(mel('programmes', $slug), [
        'code' => $code, 'name' => 'Clean water', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $programme = inAcme(fn () => Programme::query()->where('code', $code)->sole(), $slug);

    foreach (['JUB' => 'Juba', 'BOR' => 'Bor'] as $unitCode => $name) {
        test()->post(mel("programmes/{$programme->id}/units", $slug), ['code' => $unitCode, 'name' => $name, 'kind' => 'site'])->assertSessionHasNoErrors();
    }
    test()->post(mel("programmes/{$programme->id}/periods/generate", $slug), ['frequency' => 'quarterly', 'due_after_days' => 15])->assertSessionHasNoErrors();

    $levels = inAcme(fn () => $programme->levels()->get()->keyBy('depth'), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[1]->id, 'code' => '1', 'statement' => 'Healthier communities'])->assertSessionHasNoErrors();
    $goal = inAcme(fn () => Result::query()->where('code', '1')->where('programme_id', $programme->id)->sole(), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[2]->id, 'parent_id' => $goal->id, 'code' => '1.1', 'statement' => 'Households use safe water'])->assertSessionHasNoErrors();
    $outcome = inAcme(fn () => Result::query()->where('code', '1.1')->where('programme_id', $programme->id)->sole(), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[3]->id, 'parent_id' => $outcome->id, 'code' => '1.1.1', 'statement' => 'Water points built'])->assertSessionHasNoErrors();

    return inAcme(fn () => [
        'programme' => $programme,
        'units' => $programme->units()->get()->keyBy('code')->all(),
        'periods' => $programme->periods()->get()->keyBy('label')->all(),
        'output' => Result::query()->where('code', '1.1.1')->where('programme_id', $programme->id)->sole(),
    ], $slug);
}

/**
 * An active quarterly indicator under a result.
 *
 * @param  array<string, mixed>  $definition
 */
function addIndicator(Programme $programme, Result $result, string $code, array $definition = [], string $slug = 'acme'): Indicator
{
    test()->post(mel("programmes/{$programme->id}/indicators", $slug), [
        'result_id' => $result->id,
        'code' => $code,
        'frequency' => 'quarterly',
        'name' => 'People with access to safe water',
        'type' => 'output',
        'measure' => 'count',
        'unit_label' => 'people',
        'decimals' => 0,
        'direction' => 'increase',
        'time_aggregation' => 'sum',
        'unit_aggregation' => 'sum',
        ...$definition,
    ])->assertSessionHasNoErrors();

    $indicator = inAcme(fn () => Indicator::query()->where('programme_id', $programme->id)->where('code', strtoupper($code))->sole(), $slug);
    test()->put(mel("programmes/{$programme->id}/indicators/{$indicator->id}/status", $slug), ['status' => 'active'])->assertSessionHasNoErrors();

    return $indicator;
}

/**
 * Saves (and by default submits) a value through the entry form.
 *
 * @param  array<string, mixed>  $figure
 */
function enterResult(Programme $programme, ReportingPeriod $period, Indicator $indicator, ProgrammeUnit $unit, array $figure, bool $submit = true, string $slug = 'acme'): Submission
{
    test()->post(mel("programmes/{$programme->id}/periods/{$period->id}/indicators/{$indicator->id}/units/{$unit->id}", $slug), [...$figure, 'submit' => $submit])
        ->assertSessionHasNoErrors();

    return inAcme(fn () => Submission::query()
        ->where('indicator_id', $indicator->id)->where('reporting_period_id', $period->id)->where('unit_id', $unit->id)
        ->orderByDesc('revision')->firstOrFail(), $slug);
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
