<?php

use App\Models\User;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Inertia\Testing\AssertableInertia;

/*
| app.<root> is retired: account pages moved to the auth host and each
| business's pages to its own host. Old links keep working.
*/

function retiredAppUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::app(), $path);
}

function continueUrl(?string $to = null): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'workspaces/continue').($to === null ? '' : '?'.http_build_query(['to' => $to]));
}

it('sends old app links to where the page lives now, permanently', function (string $old, Closure $new) {
    $this->get(retiredAppUrl($old))->assertStatus(301)->assertRedirect($new());
})->with([
    'home' => ['/', fn () => continueUrl()],
    'dashboard' => ['dashboard', fn () => continueUrl()],
    'trial, keeping the product' => ['trial?app=retail', fn () => PlatformHosts::url(PlatformHosts::auth(), 'trial').'?app=retail'],
    'new business' => ['organizations/create', fn () => PlatformHosts::url(PlatformHosts::auth(), 'workspaces/create')],
    'invitation' => ['invitations/'.str_repeat('a', 48), fn () => PlatformHosts::url(PlatformHosts::auth(), 'invitations/'.str_repeat('a', 48))],
    'settings' => ['organization/settings', fn () => continueUrl('manage/settings')],
    'billing' => ['organization/billing', fn () => continueUrl('manage/billing')],
    'invoice' => ['organization/billing/invoices/0193b7a2-5d4e-7c1f-9a8b-123456789abc', fn () => continueUrl('manage/billing/invoices/0193b7a2-5d4e-7c1f-9a8b-123456789abc')],
    'explore' => ['explore', fn () => continueUrl('manage/explore')],
]);

it('opens the only business straight away, at the page asked for', function () {
    $organization = Organization::factory()->withSlug('solo-shop')->create();
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($membership->user)
        ->get(continueUrl('manage/billing'))
        ->assertRedirect(PlatformHosts::url(PlatformHosts::workspace('solo-shop'), 'manage/billing'));
});

it('lets members of several businesses choose, and sends people without one to create one', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(continueUrl())->assertRedirect(route('organizations.create'));

    foreach (['first-shop', 'second-shop'] as $slug) {
        OrganizationMembership::factory()->create([
            'organization_id' => Organization::factory()->withSlug($slug)->create()->id,
            'user_id' => $user->id,
        ]);
    }

    $this->get(continueUrl('manage/settings'))->assertRedirect(route('workspaces', ['to' => 'manage/settings']));
    $this->get(route('workspaces'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('central/workspaces')
        ->has('workspaces', 2));
});

it('ignores a destination that is not a business page', function () {
    $organization = Organization::factory()->withSlug('solo-shop')->create();
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($membership->user)
        ->get(continueUrl('//evil.example.test'))
        ->assertRedirect(PlatformHosts::url(PlatformHosts::workspace('solo-shop'), '/'));
});
