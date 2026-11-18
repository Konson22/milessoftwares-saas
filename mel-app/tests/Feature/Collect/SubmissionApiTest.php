<?php

use App\Collect\Models\Assignment;
use App\Collect\Models\Attachment;
use App\Collect\Models\ReviewDecision;
use App\Collect\Models\Submission;
use App\Collect\Models\SubmissionRevision;
use App\Mel\Models\AuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The field app's API (ADR-0019 §21): downloads, idempotent submissions,
 * photos one by one, assignment and version checks, corrections.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units] = setUpProgramme();
    $this->form = publishForm($this->programme->id);
    $this->version = $this->form->currentVersion;

    $this->enumerator = actingAsRole('mel_enumerator', name: 'Eli Enumerator');
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id], 'programme_unit_id' => $this->units['JUB']->id])
        ->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
});

function photoPayload(string $versionId, string $bytes, array $overrides = []): array
{
    $hash = hash('sha256', $bytes);

    return surveyPayload($versionId, [
        'answers' => ['safe_water' => 'yes', 'members' => 3, 'water_point' => $hash, 'location' => ['lat' => 4.85, 'lng' => 31.6, 'accuracy' => 8]],
        'attachments' => [['question' => 'water_point', 'sha256' => $hash, 'size' => strlen($bytes), 'mime' => 'image/png']],
        ...$overrides,
    ]);
}

it('gives the collector their assignments and the form version', function () {
    $kit = $this->getJson(collectApi('assignments'))->assertOk()->json();

    expect($kit['assignments'])->toHaveCount(1)
        ->and($kit['assignments'][0]['canStart'])->toBeTrue()
        ->and($kit['assignments'][0]['version']['id'])->toBe($this->version->id)
        ->and($kit['assignments'][0]['version']['sha256'])->toBe($this->version->sha256)
        ->and($kit['assignments'][0]['unit']['id'])->toBe($this->units['JUB']->id);

    $this->getJson(collectApi("form-versions/{$this->version->id}"))->assertOk()
        ->assertJsonPath('definition.settings.title', 'Household survey')
        ->assertHeader('Cache-Control', 'no-store, private');
    // An app that reads only an older schema is told to update.
    $this->getJson(collectApi("form-versions/{$this->version->id}?supports=0"))->assertStatus(426)->assertJsonPath('code', 'app_too_old');
});

it('tells the collector their progress on each task, by review state, and nobody else\'s', function () {
    $this->getJson(collectApi('assignments'))->assertOk()
        ->assertJsonPath('assignments.0.progress', ['received' => 0, 'pending' => 0, 'accepted' => 0, 'correction' => 0, 'rejected' => 0])
        ->assertJsonPath('assignments.0.version.publishedAt', $this->version->published_at->toIso8601String());

    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertCreated();
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertCreated();

    $this->getJson(collectApi('assignments'))->assertOk()
        ->assertJsonPath('assignments.0.progress.received', 2)
        ->assertJsonPath('assignments.0.progress.pending', 2)
        // Received is not approved.
        ->assertJsonPath('assignments.0.progress.accepted', 0);

    // Another collector on the same form starts from zero.
    $other = actingAsRole('mel_enumerator', name: 'Ola Other');
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$other->id]])->assertSessionHasNoErrors();
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $other);
    $this->getJson(collectApi('assignments'))->assertOk()->assertJsonPath('assignments.0.progress.received', 0);
});

it('takes a submission once, whatever the number of retries', function () {
    $payload = surveyPayload($this->version->id);

    $first = $this->postJson(collectApi('submissions'), $payload)->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('replayed', false);
    // The reply was lost: the phone sends the same thing again.
    $this->postJson(collectApi('submissions'), $payload)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('id', $first->json('id'));
    // A newer app version resending does not make it different.
    $this->postJson(collectApi('submissions'), [...$payload, 'app_version' => '1.0.1'])->assertOk()->assertJsonPath('replayed', true);

    $submission = inAcme(fn () => Submission::query()->sole());
    expect($submission->id)->toBe($payload['id'])
        ->and($submission->form_version_id)->toBe($this->version->id)
        ->and($submission->programme_unit_id)->toBe($this->units['JUB']->id)
        ->and($submission->answers)->toEqual(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5, 'respondent' => 'Achol Deng'])
        ->and(inAcme(fn () => ReviewDecision::query()->where('action', 'received')->count()))->toBe(1);
});

it('refuses the same id with different content, and changes nothing', function () {
    $payload = surveyPayload($this->version->id);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();

    $this->postJson(collectApi('submissions'), [...$payload, 'answers' => [...$payload['answers'], 'members' => 6]])
        ->assertStatus(409)->assertJsonPath('code', 'id_conflict');

    expect(inAcme(fn () => Submission::query()->sole()->answers['members']))->toBe(5);
});

it('validates the answers on the server with the form rules', function () {
    // Unsafe water needs a source; members must be 1..50.
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id, ['answers' => ['safe_water' => 'no', 'members' => 80]]))
        ->assertStatus(422)->assertJsonPath('code', 'invalid')
        ->assertJsonPath('errors.main_source', 'required')
        ->assertJsonPath('errors.members', 'max');

    // A hidden question's answer is dropped, never kept.
    $payload = surveyPayload($this->version->id, ['answers' => ['safe_water' => 'yes', 'main_source' => 'river', 'members' => 2, 'invented' => 'x']]);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();
    expect(inAcme(fn () => Submission::query()->sole()->answers))->toEqual(['safe_water' => 'yes', 'members' => 2]);
});

it('needs consent when the form asks for it', function () {
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id, ['consent_given' => false]))
        ->assertStatus(422)->assertJsonPath('code', 'consent_required');
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);
});

it('refuses a form the person is not assigned to', function () {
    $other = actingAsRole('mel_enumerator', name: 'Not Assigned');
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $other);

    $this->getJson(collectApi('assignments'))->assertOk()->assertJsonCount(0, 'assignments');
    $this->getJson(collectApi("form-versions/{$this->version->id}"))->assertNotFound();
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertNotFound()->assertJsonPath('code', 'form_not_found');
});

it('refuses everything after a revocation, and takes it again once reopened', function () {
    $assignment = inAcme(fn () => Assignment::query()->sole());
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/assignments/{$assignment->id}/revoke"))->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $payload = surveyPayload($this->version->id);
    $this->postJson(collectApi('submissions'), $payload)->assertForbidden()->assertJsonPath('code', 'assignment_revoked');

    // Assigned again: the same submission, kept on the phone, goes through.
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id]])->assertSessionHasNoErrors();
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();
});

it('takes work finished before an assignment closed, until the closing date', function () {
    $assignment = inAcme(fn () => Assignment::query()->sole());
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/assignments/{$assignment->id}/close"), ['closes_at' => now()->addDays(2)->toIso8601String()])->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->getJson(collectApi('assignments'))->assertOk()->assertJsonPath('assignments.0.canStart', false);
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertCreated();

    $this->travel(3)->days();
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertForbidden()->assertJsonPath('code', 'assignment_closed');
});

it('keeps each submission on the exact version it was filled in on, and closes old versions', function () {
    actingAsRole('mel_manager', $this->manager);
    $definition = surveyDefinition();
    $definition['items'][0]['items'][2]['constraint'] = ['min' => 1, 'max' => 20];
    $this->put(mel("collect/forms/{$this->form->id}/draft"), ['definition' => $definition])->assertSessionHasNoErrors();
    $this->post(mel("collect/forms/{$this->form->id}/publish"), ['accept_old_days' => 2])->assertSessionHasNoErrors();
    $v2 = inAcme(fn () => $this->form->fresh()->currentVersion);

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->getJson(collectApi('assignments'))->assertJsonPath('assignments.0.version.number', 2);

    // Filled in on version 1 offline (35 people was fine then): still accepted, on version 1.
    $old = surveyPayload($this->version->id, ['answers' => ['safe_water' => 'yes', 'members' => 35]]);
    $this->postJson(collectApi('submissions'), $old)->assertCreated();
    expect(inAcme(fn () => Submission::query()->find($old['id'])->form_version_id))->toBe($this->version->id);
    // Version 2's rule applies to version 2.
    $this->postJson(collectApi('submissions'), surveyPayload($v2->id, ['answers' => ['safe_water' => 'yes', 'members' => 35]]))
        ->assertStatus(422)->assertJsonPath('errors.members', 'max');

    $this->travel(3)->days();
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id, ['completed_at' => now()->subDays(4)->toIso8601String()]))
        ->assertStatus(409)->assertJsonPath('code', 'version_closed');
});

it('receives photos one by one, checks them, and resumes after an interruption', function () {
    $bytes = pngBytes(7);
    $payload = photoPayload($this->version->id, $bytes);

    $this->postJson(collectApi('submissions'), $payload)->assertCreated()
        ->assertJsonPath('status', 'receiving')->assertJsonPath('missing', ['water_point']);

    // Not the listed file (damaged on the way): refused, nothing stored.
    $this->call('PUT', collectApi("submissions/{$payload['id']}/attachments/water_point"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($this->enumerator->id)], pngBytes(8))
        ->assertStatus(422)->assertJsonPath('code', 'file_mismatch');
    // A file for a question with no photo listed.
    $this->call('PUT', collectApi("submissions/{$payload['id']}/attachments/members"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($this->enumerator->id)], $bytes)
        ->assertStatus(422)->assertJsonPath('code', 'unexpected_file');

    // The phone asks where it stands, then sends the file (twice: the reply was lost).
    $this->getJson(collectApi("submissions/{$payload['id']}"))->assertOk()->assertJsonPath('missing', ['water_point']);
    foreach ([1, 2] as $attempt) {
        $this->call('PUT', collectApi("submissions/{$payload['id']}/attachments/water_point"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($this->enumerator->id)], $bytes)
            ->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('missing', []);
    }

    $submission = inAcme(fn () => Submission::query()->sole());
    $file = inAcme(fn () => Attachment::query()->sole());
    expect($submission->attachments_received)->toBe(1)
        ->and((float) $submission->lat)->toBe(4.85)
        ->and($file->sha256)->toBe(hash('sha256', $bytes))
        ->and(Storage::disk('local')->get($file->path))->toBe($bytes)
        ->and(inAcme(fn () => ReviewDecision::query()->where('action', 'received')->count()))->toBe(1);
});

it('refuses a photo that is not an image, whatever it claims', function () {
    $bytes = '<?php echo "x"; ?>'.str_repeat('a', 50);
    $payload = photoPayload($this->version->id, $bytes);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();

    $this->call('PUT', collectApi("submissions/{$payload['id']}/attachments/water_point"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($this->enumerator->id)], $bytes)
        ->assertStatus(422)->assertJsonPath('code', 'file_type');
    expect(inAcme(fn () => Attachment::query()->count()))->toBe(0);
});

it('needs every photo answer listed, and nothing else', function () {
    $bytes = pngBytes();
    $this->postJson(collectApi('submissions'), photoPayload($this->version->id, $bytes, ['attachments' => []]))
        ->assertStatus(422)->assertJsonPath('errors.water_point', 'attachment');
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id, ['attachments' => [['question' => 'members', 'sha256' => hash('sha256', $bytes), 'size' => 10, 'mime' => 'image/png']]]))
        ->assertStatus(422);
});

it('lets only the author see and send to a submission', function () {
    $payload = photoPayload($this->version->id, pngBytes());
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();

    $other = actingAsRole('mel_enumerator', name: 'Other Collector');
    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $other);
    $this->getJson(collectApi("submissions/{$payload['id']}"))->assertNotFound();
    $this->call('PUT', collectApi("submissions/{$payload['id']}/attachments/water_point"), [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->platform()->appToken($other->id)], pngBytes())
        ->assertNotFound();
    // Reusing its id is a conflict, and changes nothing.
    $this->postJson(collectApi('submissions'), $payload)->assertStatus(409)->assertJsonPath('code', 'id_conflict');
    expect(inAcme(fn () => Submission::query()->sole()->author_id))->toBe($this->enumerator->id);
});

it('keeps workspaces apart, even for the same submission id', function () {
    $payload = surveyPayload($this->version->id);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();

    // Another organization, with its own form and collector, reusing the id.
    provision('globex');
    actingAsRole('mel_manager', slug: 'globex');
    $theirs = publishForm(null, null, 'Their survey', 'globex');
    $collector = actingAsRole('mel_enumerator', slug: 'globex', name: 'Globex Collector');
    actingAsRole('mel_manager', slug: 'globex');
    $this->post(mel("collect/forms/{$theirs->id}/assignments", 'globex'), ['user_ids' => [$collector->id]])->assertSessionHasNoErrors();
    $this->actingAsAppMember('globex', rolePermissions('mel_enumerator'), user: $collector);

    $this->getJson(collectApi("submissions/{$payload['id']}", 'globex'))->assertNotFound();
    $this->getJson(collectApi("form-versions/{$this->version->id}", 'globex'))->assertNotFound();
    $this->postJson(collectApi('submissions', 'globex'), surveyPayload($theirs->currentVersion->id, ['id' => $payload['id']]))
        ->assertStatus(409)->assertJsonPath('code', 'id_conflict');
});

it('lists the collector\'s changed submissions incrementally', function () {
    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertCreated()->json('id');
        $this->travel(1)->seconds();
    }

    $page = $this->getJson(collectApi('submissions?limit=2'))->assertOk()->json();
    expect(array_column($page['data'], 'id'))->toBe(array_slice($ids, 0, 2))->and($page['more'])->toBeTrue();
    $last = end($page['data']);
    $next = $this->getJson(collectApi('submissions?limit=2&since='.urlencode($last['updatedAt']).'&after='.$last['id']))->assertOk()->json();
    expect(array_column($next['data'], 'id'))->toBe([$ids[2]])->and($next['more'])->toBeFalse();
});

it('takes the collector\'s correction once, and refuses a stale one', function () {
    $payload = surveyPayload($this->version->id);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();
    // Nothing was asked for yet.
    $this->postJson(collectApi("submissions/{$payload['id']}/corrections"), ['base_revision' => 0, 'answers' => [...$payload['answers'], 'members' => 6], 'reason' => 'Miscounted'])
        ->assertStatus(409)->assertJsonPath('code', 'not_correctable');

    $reviewer = actingAsRole('mel_officer', name: 'Rita Reviewer');
    $this->post(mel("collect/submissions/{$payload['id']}/request-correction"), ['comment' => 'Recount the household', 'notes' => ['members' => 'Count children too']])->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
    $this->getJson(collectApi("submissions/{$payload['id']}"))->assertOk()
        ->assertJsonPath('status', 'correction_requested')
        ->assertJsonPath('correction.comment', 'Recount the household')
        ->assertJsonPath('correction.notes.members', 'Count children too')
        ->assertJsonPath('correction.answers.members', 5);

    $correction = ['base_revision' => 0, 'answers' => [...$payload['answers'], 'members' => 7], 'reason' => 'Counted the children'];
    $this->postJson(collectApi("submissions/{$payload['id']}/corrections"), $correction)->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('revision', 1);
    // Sent again after a lost reply: recognized, not made twice.
    $this->postJson(collectApi("submissions/{$payload['id']}/corrections"), $correction)->assertOk()->assertJsonPath('replayed', true);
    // Started from an old revision with other answers: a conflict, never an overwrite.
    $this->postJson(collectApi("submissions/{$payload['id']}/corrections"), [...$correction, 'answers' => [...$payload['answers'], 'members' => 9]])
        ->assertStatus(409)->assertJsonPath('code', 'revision_conflict');

    $submission = inAcme(fn () => Submission::query()->with('currentRevision')->sole());
    expect($submission->answers['members'])->toBe(5)
        ->and($submission->currentAnswers()['members'])->toBe(7)
        ->and($submission->currentRevision->changed)->toBe(['members'])
        ->and(inAcme(fn () => SubmissionRevision::query()->count()))->toBe(1);
});

it('needs the submit permission and a valid token', function () {
    $viewer = actingAsRole('mel_donor_viewer', name: 'Viewer Only');
    $this->actingAsAppMember('acme', rolePermissions('mel_donor_viewer'), user: $viewer);
    $this->getJson(collectApi('assignments'))->assertForbidden();

    $this->withToken('not-a-token')->getJson(collectApi('assignments'))->assertUnauthorized();
});

it('audits what arrives', function () {
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id))->assertCreated();

    expect(inAcme(fn () => AuditEvent::query()->where('action', 'collected.received')->count()))->toBe(1);
});

it('refuses an id that is not a uuid', function () {
    $this->postJson(collectApi('submissions'), surveyPayload($this->version->id, ['id' => 'abc']))->assertStatus(422);
    $this->getJson(collectApi('submissions/'.Str::random(8)))->assertNotFound();
});

it('tells the app who and where it is signed in', function () {
    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))->assertOk()
        ->assertJsonPath('user.id', $this->enumerator->id)
        ->assertJsonPath('organization.slug', 'acme')
        ->assertJsonPath('canCollect', true);
});

it('takes a photo sent as multipart, as the phone sends it', function () {
    $bytes = pngBytes(11);
    $payload = photoPayload($this->version->id, $bytes);
    $this->postJson(collectApi('submissions'), $payload)->assertCreated();

    $this->post(collectApi("submissions/{$payload['id']}/attachments/water_point"), [
        '_method' => 'PUT',
        'file' => UploadedFile::fake()->createWithContent('water_point.jpg', $bytes),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'submitted');

    expect(Storage::disk('local')->get(inAcme(fn () => Attachment::query()->sole()->path)))->toBe($bytes);
});
