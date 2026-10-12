<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
});

it('works in the only branch without asking', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create']);

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('branch.name', 'Juba')
            ->where('canSwitchBranch', false));
});

it('asks which branch when several are open, then remembers it', function () {
    $wau = $this->platform()->branch('acme', 'Wau', 'WAU');
    $this->actingAsMember('acme', ['pharmacy.sales.create']);

    $this->get($this->workspaceUrl('acme'))->assertRedirect($this->workspaceUrl('acme', 'branch'));

    $this->get($this->workspaceUrl('acme', 'branch'))
        ->assertInertia(fn (Assert $page) => $page->component('branch')->has('branches', 2));

    $this->put($this->workspaceUrl('acme', 'branch'), ['branch_id' => $wau])
        ->assertRedirect($this->workspaceUrl('acme'));

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('branch.name', 'Wau')
            ->where('canSwitchBranch', true));
});

it('limits a branch-scoped member to their branch', function () {
    $wau = $this->platform()->branch('acme', 'Wau', 'WAU');
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $wau);

    // Only Wau is open, so it is chosen without asking.
    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Wau'));

    // Juba exists but is not theirs: it looks unknown.
    $this->put($this->workspaceUrl('acme', 'branch'), ['branch_id' => $this->juba])->assertNotFound();
});

it('leaves inactive branches out', function () {
    $this->platform()->branches['acme'][] = ['id' => '0199a0b0-0000-7000-8000-0000000c1053', 'name' => 'Closed', 'code' => 'CLO', 'city' => null, 'status' => 'inactive'];
    $this->actingAsMember('acme', ['pharmacy.sales.create']);

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page->where('branch.name', 'Juba'));
});

it('refuses members with no role in any active branch', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: '0199a0b0-0000-7000-8000-0000000ab5e7');

    $this->get($this->workspaceUrl('acme'))->assertForbidden();
});
