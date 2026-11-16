<?php

use App\Collaboration\Models\Invitation;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use App\Notifications\InvitedAsBeneficiary;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 5, invitations: an address is never invited only once. One invitation
| waits at a time for the same purpose; one that expired, was declined or
| withdrawn never stands in the way, and history is kept.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00'));
    Notification::fake();
});

function lifecycleDonor(string $slug = 'global-fund'): Organization
{
    $organization = test()->organization($slug);
    $organization->forceFill(['capabilities' => [Organization::DONOR]])->save();
    test()->actingAsMember($slug, [], owner: true);

    return $organization;
}

function lifecycleInvite(string $email, string $name = 'Water Now', string $donor = 'global-fund'): TestResponse
{
    return test()->post(test()->organizationUrl($donor, 'beneficiaries'), [
        'name' => $name, 'label' => 'implementer', 'email' => $email, 'currency' => 'kes', 'timezone' => 'Africa/Nairobi',
    ]);
}

/** The token in the latest email to $email. */
function lifecycleToken(string $email): string
{
    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitedAsBeneficiary::class, function (InvitedAsBeneficiary $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $url = $notification->url;
        }

        return true;
    });

    return basename((string) parse_url((string) $url, PHP_URL_PATH));
}

it('keeps one beneficiary invitation waiting per address, and lets it be sent again whatever became of the last', function () {
    lifecycleDonor();
    lifecycleInvite('Admin@Water.example')->assertSessionHasNoErrors();

    // Waiting already: resend that one instead (it says which).
    lifecycleInvite('admin@water.example', 'Water Again')
        ->assertSessionHasErrors(['email' => 'An invitation to this address is waiting already (for Water Now, sent 2026-11-17). Resend it from the list instead.']);
    $this->flushSession();
    expect(Partnership::query()->count())->toBe(1);

    // Expired: a new one goes out.
    $this->travel((int) config('access.invitation_days') + 1)->days();
    lifecycleInvite('admin@water.example')->assertSessionHasNoErrors();

    // Declined: invited again.
    $token = lifecycleToken('admin@water.example');
    $this->post($this->organizationUrl('global-fund', "partnerships/{$token}/decline"))->assertRedirect();
    lifecycleInvite('admin@water.example')->assertSessionHasNoErrors();

    expect(Partnership::query()->orderBy('created_at')->get()->map(fn (Partnership $p) => match (true) {
        $p->isDeclined() => 'declined', $p->isPending() => 'waiting', default => 'expired',
    })->all())->toBe(['expired', 'declined', 'waiting']);

    // The same address from another donor is another relationship.
    lifecycleDonor('unicef');
    lifecycleInvite('admin@water.example', 'Water Now', 'unicef')->assertSessionHasNoErrors();
});

it('keeps a withdrawn invitation for history, and its link stops working', function () {
    lifecycleDonor();
    lifecycleInvite('admin@water.example')->assertSessionHasNoErrors();
    $token = lifecycleToken('admin@water.example');
    $partnership = Partnership::query()->sole();

    $this->delete($this->organizationUrl('global-fund', "beneficiaries/{$partnership->id}"))->assertSessionHasNoErrors();

    // Gone from every list and lookup, as when it was deleted...
    expect(Partnership::query()->count())->toBe(0);
    $this->get($this->organizationUrl('global-fund', 'beneficiaries'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('partnerships', 0));
    $this->actingAsMember('water-now', [], owner: true);
    $this->post($this->organizationUrl('water-now', "partnerships/{$token}"))->assertNotFound();

    // ...but kept, with who withdrew it, and the address can be invited again.
    $kept = Partnership::query()->withoutGlobalScope('live')->sole();
    expect($kept->revoked_at)->not->toBeNull()->and($kept->revoked_by)->not->toBeNull()->and($kept->token_hash)->toBeNull()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'beneficiary.invitation_revoked')->count())->toBe(1);
    $this->actingAsMember('global-fund', [], owner: true);
    lifecycleInvite('admin@water.example')->assertSessionHasNoErrors();
});

it('explains an older invitation whose organization is connected already', function () {
    lifecycleDonor();
    lifecycleInvite('admin@water.example', 'Inov')->assertSessionHasNoErrors();
    $older = lifecycleToken('admin@water.example');
    // Withdrawn tokens aside, a second live one needs the first expired.
    $this->travel((int) config('access.invitation_days') + 1)->days();
    lifecycleInvite('admin@water.example', 'Water Now')->assertSessionHasNoErrors();
    $newer = lifecycleToken('admin@water.example');
    expect($newer)->not->toBe($older);

    // Sent to the same address by the same donor while the newer one waits: refused.
    lifecycleInvite('admin@water.example', 'Water Now again')->assertSessionHasErrors('email');
    $this->flushSession();

    // Another donor's invitation to the same person, and its organization connects to both.
    lifecycleDonor('unicef');
    lifecycleInvite('admin@water.example', 'Water Now', 'unicef')->assertSessionHasNoErrors();
    $unicef = lifecycleToken('admin@water.example');

    $admin = User::factory()->create(['email' => 'admin@water.example']);
    $this->actingAsMember('water-now', [], owner: true, user: $admin);
    $this->post($this->organizationUrl('water-now', "partnerships/{$newer}"))->assertSessionHasNoErrors()->assertRedirect();
    $this->post($this->organizationUrl('water-now', "partnerships/{$unicef}"))->assertSessionHasNoErrors()->assertRedirect();
    expect(Partnership::query()->whereNotNull('accepted_at')->count())->toBe(2);
});

it('says plainly that an organization is connected already when an old link is used', function () {
    lifecycleDonor();
    lifecycleInvite('one@water.example', 'Inov')->assertSessionHasNoErrors();
    lifecycleInvite('two@water.example', 'Water Now')->assertSessionHasNoErrors();
    $first = lifecycleToken('one@water.example');
    $second = lifecycleToken('two@water.example');

    $this->actingAsMember('water-now', [], owner: true);
    $this->post($this->organizationUrl('water-now', "partnerships/{$second}"))->assertSessionHasNoErrors()->assertRedirect();
    $this->post($this->organizationUrl('water-now', "partnerships/{$first}"))
        ->assertStatus(409)->assertSee('is already connected to', false);
});

it('invites an organization to a project again once its invitation expired', function () {
    $project = collabProject();
    $first = inviteToProject($project, 'water-now');

    $this->travel((int) config('access.invitation_days') + 1)->days();
    // Before: the database refused a second unanswered invitation outright.
    $second = inviteToProject($project, 'water-now');

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()?->status())->toBe(Invitation::REVOKED)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'collaboration.invitation_superseded')->exists())->toBeTrue();

    // While the new one waits, a third is refused: resend instead.
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertSessionHasErrors('organization_id');
});

it('invites the same address to different projects, but once at a time to each', function () {
    $wash = collabProject('acme', 'WASH');
    $edu = collabProject('acme', 'EDU');
    $invite = fn ($project) => $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'email' => 'lead@partner.example', 'contact_name' => 'Lead', 'relationship' => 'implementer', 'capabilities' => ['view'],
    ]);

    $invite($wash)->assertSessionHasNoErrors();
    $invite($edu)->assertSessionHasNoErrors();
    $invite($wash)->assertSessionHasErrors(['email' => 'That address has an invitation waiting already. Resend it instead.']);
    $this->flushSession();

    $this->travel((int) config('access.invitation_days') + 1)->days();
    $invite($wash)->assertSessionHasNoErrors();
    expect(Invitation::query()->where('email', 'lead@partner.example')->count())->toBe(3);
});
