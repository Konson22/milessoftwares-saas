<?php

use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    $this->catalogAdmin = $this->staffWith(['catalog_admin']);
    $this->base = PlatformHosts::url(PlatformHosts::admin(), "applications/{$this->retail->id}/integration");
});

it('registers a per-tenant sign-in client with callbacks only for entitled organizations', function () {
    $notEntitled = Organization::factory()->create();

    $this->actingAs($this->catalogAdmin->user)
        ->post("{$this->base}/sign-in-clients", ['name' => 'Retail web', 'confidential' => '1', 'tenant_redirect_path' => '/retail/auth/callback'])
        ->assertSessionHas('issued_secret', fn (array $issued) => $issued['client_secret'] !== null);

    $uris = DB::table('application_client_redirect_uris')
        ->join('application_clients', 'application_clients.id', '=', 'application_client_redirect_uris.application_client_id')
        ->where('application_clients.tenant_redirect_path', '/retail/auth/callback')
        ->pluck('uri', 'organization_id');

    expect($uris->all())->toBe([
        $this->organization->id => PlatformHosts::url(PlatformHosts::workspace($this->organization->primarySlug->slug), 'retail/auth/callback'),
    ])->and($uris->has($notEntitled->id))->toBeFalse();

    // Entitling the second organization adds its callback.
    app(ManageEntitlements::class)->grantManual($notEntitled, $this->retail, $this->staffWith(['billing_admin']), 'Pilot');

    expect(DB::table('application_client_redirect_uris')->where('organization_id', $notEntitled->id)->exists())->toBeTrue();
});

it('shows a new secret once only', function () {
    $this->actingAs($this->catalogAdmin->user)->post("{$this->base}/service-clients", ['name' => 'Retail backend 2']);

    $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page->whereNot('issuedSecret', null));
    $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page->where('issuedSecret', null));
});

it('rejects public clients without any callback', function () {
    $this->actingAs($this->catalogAdmin->user)
        ->post("{$this->base}/sign-in-clients", ['name' => 'Broken', 'confidential' => '0'])
        ->assertStatus(422);
});

it('registers a public mobile app client on its own scheme, never a confidential one', function () {
    $this->actingAs($this->catalogAdmin->user)
        ->post("{$this->base}/sign-in-clients", ['name' => 'Retail mobile', 'confidential' => '0', 'redirect_uris' => 'com.milesoftwares.retail:/oauth'])
        ->assertSessionHas('issued_secret', fn (array $issued) => $issued['client_secret'] === null);

    $this->post("{$this->base}/sign-in-clients", ['name' => 'Bad', 'confidential' => '1', 'redirect_uris' => 'com.milesoftwares.retail:/oauth'])->assertStatus(422);
    // A bare scheme anyone could claim.
    $this->post("{$this->base}/sign-in-clients", ['name' => 'Bad', 'confidential' => '0', 'redirect_uris' => 'retail:/oauth'])->assertStatus(422);
});

it('keeps integration settings to catalog administrators', function () {
    $this->actingAs($this->staffWith(['billing_admin'])->user)->get($this->base)->assertForbidden();
});
