<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
 * Collection analytics on the web, and the collector's own activity in the
 * phone's kit.
 */

beforeEach(function () {
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme] = setUpProgramme();
    $this->form = publishForm($this->programme->id);
    $this->enumerator = actingAsRole('mel_enumerator', name: 'Eli Enumerator');
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id], 'expected_submissions' => 4])->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->first = surveyPayload($this->form->currentVersion->id);
    $this->postJson(collectApi('submissions'), $this->first)->assertCreated();
    $this->postJson(collectApi('submissions'), surveyPayload($this->form->currentVersion->id))->assertCreated();
});

it('counts what came in by review outcome, form, collector and target', function () {
    $this->flushHeaders();
    actingAsRole('mel_officer', name: 'Otto Officer');
    $this->post(mel("collect/submissions/{$this->first['id']}/request-correction"), ['comment' => 'Check'])->assertSessionHasNoErrors();

    $this->get(mel('collect/analytics?days=90'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/analytics')
        ->where('filters.days', 90)
        ->where('unit', 'week')
        ->where('totals.received', 2)
        ->where('totals.awaiting', 1)
        ->where('totals.correction', 1)
        ->where('totals.collectors', 1)
        ->where('totals.acceptanceRate', 0)
        ->where('trend', fn ($rows) => collect($rows)->sum(fn ($r) => $r['awaiting'] + $r['correction']) === 2)
        ->where('byForm.0.awaiting', 1)
        ->where('byCollector.0.name', 'Eli Enumerator')
        ->where('byCollector.0.count', 2)
        ->where('targets.0.expected', 4)
        ->where('targets.0.sent', 2));
});

it('falls back to 30 days for an unknown period', function () {
    $this->flushHeaders();
    actingAsRole('mel_manager', $this->manager);
    $this->get(mel('collect/analytics?days=7'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.days', 30)->where('unit', 'day')->has('trend', 30));
});

it('sends the collector their own activity per day', function () {
    $this->getJson(collectApi('assignments'))->assertOk()
        ->assertJsonCount(30, 'activity')
        ->assertJsonPath('activity.29.date', now()->toDateString())
        ->assertJsonPath('activity.29.count', 2);
});
