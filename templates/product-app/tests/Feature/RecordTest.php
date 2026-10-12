<?php

use App\Models\Record;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only the workspace\'s own records', function () {
    $member = $this->actingAsMember('acme', ['blueprint.records.view']);
    $author = User::factory()->create();

    $this->asTenant('acme', fn () => (new Record(['title' => 'Acme record']))->forceFill(['created_by' => $author->id])->save());
    $this->asTenant('globex', fn () => (new Record(['title' => 'Globex record']))->forceFill(['created_by' => $author->id])->save());

    $this->get($this->workspaceUrl('acme', 'records'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('records/index')
            ->has('records', 1)
            ->where('records.0.title', 'Acme record')
            ->where('canCreate', false));
});

it('needs the view permission to see records', function () {
    $this->actingAsMember('acme', ['blueprint.something.else']);

    $this->get($this->workspaceUrl('acme', 'records'))->assertForbidden();
});

it('lets editors add records to their workspace', function () {
    $member = $this->actingAsMember('acme', ['blueprint.records.view', 'blueprint.records.manage']);

    $this->post($this->workspaceUrl('acme', 'records'), ['title' => 'New record'])
        ->assertRedirect($this->workspaceUrl('acme', 'records'));

    $record = $this->asTenant('acme', fn () => Record::query()->sole());
    expect($record)
        ->title->toBe('New record')
        ->created_by->toBe($member->getAuthIdentifier())
        ->organization_id->toBe($this->platform()->organization('acme')['id']);
});

it('refuses to add records without the manage permission', function () {
    $this->actingAsMember('acme', ['blueprint.records.view']);

    $this->post($this->workspaceUrl('acme', 'records'), ['title' => 'Nope'])->assertForbidden();

    expect($this->asTenant('acme', fn () => Record::query()->count()))->toBe(0);
});

it('gives the organization owner every permission', function () {
    $this->actingAsMember('acme', owner: true);

    $this->post($this->workspaceUrl('acme', 'records'), ['title' => 'By the owner'])->assertRedirect();
});
