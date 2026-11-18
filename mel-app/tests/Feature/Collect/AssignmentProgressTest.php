<?php

use App\Collect\Models\Assignment;
use App\Collect\Models\Form;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Field assignments with an expected number of submissions: progress of
 * the collection, kept apart from the review of what was collected
 * (ADR-0019 §26), and the form's project milestone.
 */

beforeEach(function () {
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme] = setUpProgramme();
    $this->form = publishForm($this->programme->id);
    $this->enumerator = actingAsRole('mel_enumerator', name: 'Eli Enumerator');
    $this->late = actingAsRole('mel_enumerator', name: 'Lena Late');
    actingAsRole('mel_manager', $this->manager);
});

it('tracks what each person sent against what was expected, apart from review', function () {
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id], 'expected_submissions' => 2])->assertSessionHasNoErrors();
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), [
        'user_ids' => [$this->late->id], 'expected_submissions' => 5, 'starts_on' => now()->subDays(20)->toDateString(), 'ends_on' => now()->subDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $first = surveyPayload($this->form->currentVersion->id);
    $this->postJson(collectApi('submissions'), $first)->assertCreated();
    $this->postJson(collectApi('submissions'), surveyPayload($this->form->currentVersion->id))->assertCreated();

    // The phone learns how many are expected.
    $this->getJson(collectApi('assignments'))->assertOk()->assertJsonPath('assignments.0.expected', 2);
    $this->flushHeaders();

    actingAsRole('mel_officer', name: 'Otto Officer');
    $this->post(mel("collect/submissions/{$first['id']}/request-correction"), ['comment' => 'Check the count'])->assertSessionHasNoErrors();

    $this->get(mel('collect/assignments'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/assignments/index')
        ->where('assignments', fn ($rows) => collect($rows)->contains(fn ($row) => $row['person'] === 'Eli Enumerator'
            && $row['expected'] === 2
            && $row['collection'] === 'complete'
            && $row['progress']['done'] === 2
            && $row['progress']['awaitingReview'] === 1
            && $row['progress']['correction'] === 1
            && $row['progress']['accepted'] === 0)
            && collect($rows)->contains(fn ($row) => $row['person'] === 'Lena Late'
                && $row['collection'] === 'overdue'
                && $row['progress']['done'] === 0)));
});

it('refuses an expected number out of range', function () {
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id], 'expected_submissions' => 0])
        ->assertSessionHasErrors('expected_submissions');
    expect(inAcme(fn () => Assignment::query()->count()))->toBe(0);
});

it('keeps a form\'s milestone as it is while Projects is not connected', function () {
    inAcme(fn () => Form::query()->whereKey($this->form->id)->update(['linked_milestone_id' => '0193a2f4-0000-7000-8000-000000000001']));

    $this->get(mel("collect/forms/{$this->form->id}/edit"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('form.linkedMilestoneId', '0193a2f4-0000-7000-8000-000000000001'));

    $this->put(mel("collect/forms/{$this->form->id}"), [
        'name' => 'Household survey', 'purpose' => 'survey', 'programme_id' => $this->programme->id, 'linked_milestone_id' => null,
    ])->assertSessionHasNoErrors();
    expect(inAcme(fn () => $this->form->fresh()->linked_milestone_id))->toBe('0193a2f4-0000-7000-8000-000000000001');

    // Assignments carry the form's milestone.
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id]])->assertSessionHasNoErrors();
    expect(inAcme(fn () => Assignment::query()->sole()->linked_milestone_id))->toBe('0193a2f4-0000-7000-8000-000000000001');

    // Moving the form to no programme drops it.
    $this->put(mel("collect/forms/{$this->form->id}"), ['name' => 'Household survey', 'purpose' => 'survey', 'programme_id' => null])->assertSessionHasNoErrors();
    expect(inAcme(fn () => $this->form->fresh()->linked_milestone_id))->toBeNull();
});
