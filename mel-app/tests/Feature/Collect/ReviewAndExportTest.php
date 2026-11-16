<?php

use App\Collect\Models\Attachment;
use App\Collect\Models\Export;
use App\Collect\Models\Submission;
use App\Collect\Models\SubmissionRevision;
use App\Mel\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Review of collected data on the web, photos, corrections, the per-form
 * summary and exports (ADR-0019 §21).
 */

const REVIEW_NO_PERSONAL = ['mel.programmes.view', 'mel.programmes.all', 'mel.collect.view', 'mel.collect.review', 'mel.collect.export'];

beforeEach(function () {
    Storage::fake('local');
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme] = setUpProgramme();
    $this->form = publishForm($this->programme->id);
    $this->version = $this->form->currentVersion;
    $this->enumerator = actingAsRole('mel_enumerator', name: 'Eli Enumerator');
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id, $this->manager->id]])->assertSessionHasNoErrors();

    // One plain submission, and one with a photo.
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->plain = surveyPayload($this->version->id, ['answers' => ['safe_water' => 'no', 'main_source' => 'river', 'members' => 5, 'respondent' => '=HYPERLINK("http://x")']]);
    $this->postJson(collectApi('submissions'), $this->plain)->assertCreated();
    $this->bytes = pngBytes(3);
    $hash = hash('sha256', $this->bytes);
    $this->photo = surveyPayload($this->version->id, [
        'answers' => ['safe_water' => 'yes', 'members' => 2, 'water_point' => $hash],
        'attachments' => [['question' => 'water_point', 'sha256' => $hash, 'size' => strlen($this->bytes), 'mime' => 'image/png']],
    ]);
    $this->postJson(collectApi('submissions'), $this->photo)->assertCreated();
    $this->call('PUT', collectApi("submissions/{$this->photo['id']}/attachments/water_point"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($this->enumerator->id)], $this->bytes)->assertOk();
    $this->flushHeaders();

    $this->officer = actingAsRole('mel_officer', name: 'Otto Officer');
});

it('lists the review queue and renders a submission by its own version', function () {
    $this->get(mel('collect/submissions'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/submissions/index')
        ->has('submissions.data', 2)
        ->where('counts.submitted', 2));

    $this->get(mel("collect/submissions/{$this->plain['id']}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/submissions/show')
        ->where('submission.version.number', 1)
        ->where('answers.1.name', 'main_source')
        ->where('answers.1.value', 'River')
        ->where('answers.3.value', '=HYPERLINK("http://x")')
        ->where('can.review', true));
});

it('withholds personal answers and photos from reviewers without personal-data access', function () {
    $this->actingAsMember('acme', REVIEW_NO_PERSONAL, user: $this->platformUser(['name' => 'No Personal']));

    $this->get(mel("collect/submissions/{$this->photo['id']}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('personalHidden', true)
        ->where('answers.3.name', 'respondent')->where('answers.3.hidden', true)->where('answers.3.value', null)
        ->where('answers.4.name', 'water_point')->where('answers.4.hidden', true)->where('answers.4.attachmentId', null)
        ->where('submission.place', null)
        ->where('correction', null));

    $attachment = inAcme(fn () => Attachment::query()->sole());
    $this->get(mel("collect/submissions/{$this->photo['id']}/photos/{$attachment->id}"))->assertForbidden();
});

it('serves photos only through the authorized controller, and audits each view', function () {
    $attachment = inAcme(fn () => Attachment::query()->sole());

    $response = $this->get(mel("collect/submissions/{$this->photo['id']}/photos/{$attachment->id}"))->assertOk();
    expect($response->streamedContent())->toBe($this->bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and(inAcme(fn () => AuditEvent::query()->where('action', 'collected.photo_viewed')->count()))->toBe(1);

    // A file of another submission is not reachable through this one.
    $this->get(mel("collect/submissions/{$this->plain['id']}/photos/{$attachment->id}"))->assertNotFound();
});

it('reviews: start, request a correction, accept, and locks the decision', function () {
    $id = $this->plain['id'];
    $this->post(mel("collect/submissions/{$id}/start"))->assertSessionHasNoErrors();
    $this->post(mel("collect/submissions/{$id}/request-correction"), ['comment' => ''])->assertSessionHasErrors('comment');
    $this->post(mel("collect/submissions/{$id}/request-correction"), ['comment' => 'Check the source', 'notes' => ['main_source' => 'Was it the borehole?', 'not_a_question' => 'x']])->assertSessionHasNoErrors();
    $this->post(mel("collect/submissions/{$id}/accept"))->assertSessionHasErrors('status');

    // The collector corrects from the phone; it is back for review.
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->postJson(collectApi("submissions/{$id}/corrections"), ['base_revision' => 0, 'answers' => [...$this->plain['answers'], 'main_source' => 'vendor'], 'reason' => 'Asked again'])->assertCreated();

    actingAsRole('mel_officer', $this->officer);
    $this->get(mel("collect/submissions/{$id}"))->assertInertia(fn (Assert $page) => $page
        ->where('submission.status', 'submitted')
        ->where('answers.1.value', 'Vendor')->where('answers.1.original', 'River')->where('answers.1.changed', true)
        ->has('revisions', 1)
        ->where('decisions.2.action', 'correction_requested')
        ->where('decisions.2.notes', ['main_source' => 'Was it the borehole?']));

    $this->post(mel("collect/submissions/{$id}/accept"))->assertSessionHasNoErrors();
    expect(inAcme(fn () => Submission::query()->find($id)->status))->toBe(Submission::ACCEPTED);

    // Accepted is locked: no correction until reopened, with a reason.
    $this->post(mel("collect/submissions/{$id}/corrections"), ['base_revision' => 1, 'answers' => [...$this->plain['answers'], 'members' => 4], 'reason' => 'Typo'])->assertSessionHasErrors('answers');
    $this->post(mel("collect/submissions/{$id}/reopen"), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(mel("collect/submissions/{$id}/reopen"), ['reason' => 'Members look wrong'])->assertSessionHasNoErrors();
    $this->post(mel("collect/submissions/{$id}/corrections"), ['base_revision' => 1, 'answers' => [...$this->plain['answers'], 'main_source' => 'vendor', 'members' => 4], 'reason' => 'Typo'])->assertSessionHasNoErrors();

    $submission = inAcme(fn () => Submission::query()->with('decisions')->find($id));
    expect($submission->status)->toBe(Submission::UNDER_REVIEW)
        ->and($submission->answers['main_source'])->toBe('river')
        ->and($submission->currentAnswers()['members'])->toBe(4)
        ->and($submission->decisions->pluck('action')->all())->toBe(['received', 'review_started', 'correction_requested', 'corrected', 'accepted', 'reopened', 'corrected']);
});

it('rejects with a reason and keeps the record', function () {
    $this->post(mel("collect/submissions/{$this->plain['id']}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(mel("collect/submissions/{$this->plain['id']}/reject"), ['reason' => 'Duplicate household'])->assertSessionHasNoErrors();

    expect(inAcme(fn () => Submission::query()->find($this->plain['id'])->status))->toBe(Submission::REJECTED);
    $this->get(mel('collect/submissions?view=rejected'))->assertInertia(fn (Assert $page) => $page->has('submissions.data', 1));
});

it('never lets a collector review their own submission', function () {
    actingAsRole('mel_manager', $this->manager);
    $this->actingAsAppMember('acme', rolePermissions('mel_manager'), user: $this->manager);
    $own = surveyPayload($this->version->id);
    $this->postJson(collectApi('submissions'), $own)->assertCreated();
    $this->flushHeaders();

    actingAsRole('mel_manager', $this->manager);
    $this->get(mel("collect/submissions/{$own['id']}"))->assertInertia(fn (Assert $page) => $page->where('can.review', false)->where('ownWork', true));
    $this->post(mel("collect/submissions/{$own['id']}/accept"))->assertSessionHasErrors('status');
    expect(inAcme(fn () => Submission::query()->find($own['id'])->status))->toBe(Submission::SUBMITTED);
});

it('keeps collected data from donors, partners, evaluators and other workspaces', function () {
    foreach (['mel_donor_viewer', 'mel_partner', 'mel_evaluator', 'mel_enumerator'] as $role) {
        actingAsRole($role);
        $this->get(mel('collect/submissions'))->assertForbidden();
        $this->get(mel("collect/submissions/{$this->plain['id']}"))->assertForbidden();
        $this->get(mel('collect/exports'))->assertForbidden();
    }

    provision('globex');
    actingAsRole('mel_administrator', slug: 'globex');
    $this->get(mel("collect/submissions/{$this->plain['id']}", 'globex'))->assertNotFound();
    $this->post(mel("collect/submissions/{$this->plain['id']}/accept", 'globex'))->assertNotFound();
    $this->get(mel('collect/submissions', 'globex'))->assertInertia(fn (Assert $page) => $page->has('submissions.data', 0));
});

it('summarizes accepted data only', function () {
    $this->post(mel("collect/submissions/{$this->plain['id']}/accept"))->assertSessionHasNoErrors();

    $this->get(mel("collect/forms/{$this->form->id}/summary"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('accepted', 1)
        ->where('questions.0.name', 'safe_water')
        ->where('questions.0.choices.1.count', 1)
        ->where('questions.2.name', 'members')
        ->where('questions.2.stats.median', 5));
});

it('exports with formula-safe cells and without personal answers when not allowed', function () {
    $this->post(mel("collect/submissions/{$this->plain['id']}/accept"))->assertSessionHasNoErrors();

    // With personal data: the name is there, escaped.
    $this->post(mel("collect/forms/{$this->form->id}/exports"), ['format' => 'csv', 'statuses' => ['accepted']])->assertSessionHasNoErrors();
    $export = inAcme(fn () => Export::query()->sole());
    expect($export->status)->toBe(Export::READY)->and($export->rows)->toBe(1)->and($export->includes_personal_data)->toBeTrue();
    $csv = $this->get(mel("collect/exports/{$export->id}/download"))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain('safe_water')->toContain('respondent')->not->toContain('water_point');

    // Without: left out, with a note.
    $reviewer = $this->platformUser(['name' => 'No Personal']);
    $this->actingAsMember('acme', REVIEW_NO_PERSONAL, user: $reviewer);
    $this->post(mel("collect/forms/{$this->form->id}/exports"), ['format' => 'xlsx'])->assertSessionHasNoErrors();
    $theirs = inAcme(fn () => Export::query()->where('requested_by', $reviewer->id)->sole());
    expect($theirs->includes_personal_data)->toBeFalse()->and($theirs->rows)->toBe(2);
    $this->get(mel("collect/exports/{$theirs->id}/download"))->assertOk();
    // Someone else's export is not theirs to download.
    $this->get(mel("collect/exports/{$export->id}/download"))->assertNotFound();

    expect(inAcme(fn () => AuditEvent::query()->where('action', 'export.downloaded')->count()))->toBe(2);
});

it('re-checks an export at download: expiry and personal-data access', function () {
    $this->post(mel("collect/forms/{$this->form->id}/exports"), ['format' => 'csv'])->assertSessionHasNoErrors();
    $export = inAcme(fn () => Export::query()->sole());

    // The officer loses personal-data access: the file is no longer theirs to take.
    $this->actingAsMember('acme', REVIEW_NO_PERSONAL, user: $this->officer);
    $this->travel(2)->minutes(); // past the cached access (max_age 60 s)
    $this->get(mel("collect/exports/{$export->id}/download"))->assertForbidden();

    actingAsRole('mel_officer', $this->officer);
    $this->travel(8)->days();
    actingAsRole('mel_officer', $this->officer);
    $this->get(mel("collect/exports/{$export->id}/download"))->assertStatus(410);
});

it('locks collected answers and decided submissions in the database itself (PostgreSQL)', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The triggers are PostgreSQL only.');
    }
    $this->post(mel("collect/submissions/{$this->plain['id']}/accept"))->assertSessionHasNoErrors();

    inAcme(function () {
        $submission = Submission::query()->find($this->plain['id']);

        expect(fn () => DB::transaction(fn () => SubmissionRevision::query()->create([
            'submission_id' => $submission->id, 'number' => 1, 'answers' => ['members' => 9], 'changed' => ['members'],
            'reason' => 'Behind the review\'s back', 'author_id' => $this->officer->id, 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'locked');

        expect(fn () => DB::transaction(fn () => Submission::query()->whereKey($submission->id)->update(['answers' => json_encode(['members' => 9])])))
            ->toThrow(QueryException::class, 'cannot be changed');

        expect(fn () => DB::transaction(fn () => Submission::query()->whereKey($submission->id)->update(['status' => 'deleted'])))
            ->toThrow(QueryException::class);
    });
});
