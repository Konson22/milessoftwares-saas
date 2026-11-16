<?php

use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Notifications\OrganizationInvitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    $this->prepareAuthorizationFixtures();
    app(TenantContext::class)->clear();
});

function reinviteAt(string $path): string
{
    return 'http://'.test()->organization->primarySlug->slug.'.'.config('platform.root_domain').$path;
}

it('invites the same address again once its invitation expired, revoked or not', function () {
    $this->actingAs($this->owner->user);

    $this->post(reinviteAt('/manage/invitations'), ['email' => 'Amina@example.test'])->assertSessionHasNoErrors();
    // Still waiting: one at a time, resend instead.
    $this->post(reinviteAt('/manage/invitations'), ['email' => 'amina@example.test'])->assertSessionHasErrors();

    // Expired, and the hourly expiry has not run yet: a new invitation goes out.
    $this->travel(8)->days();
    $this->post(reinviteAt('/manage/invitations'), ['email' => 'amina@example.test'])->assertSessionHasNoErrors();

    $statuses = Invitation::acrossTenants()->orderBy('created_at')->pluck('status')->all();
    expect($statuses)->toBe(['expired', 'pending']);
    Notification::assertSentTimes(OrganizationInvitation::class, 2);
});
