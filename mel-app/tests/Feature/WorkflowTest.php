<?php

use App\Models\AuditEvent;
use App\Models\Dimension;
use App\Models\EvidenceFile;
use App\Models\IndicatorVersion;
use App\Models\ReviewDecision;
use App\Models\Submission;
use App\Models\VerifiedResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    provision();
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'periods' => $this->periods, 'output' => $this->output] = setUpProgramme();
    $this->indicator = addIndicator($this->programme, $this->output, 'OP1');
    $this->q1 = $this->periods['2026 Q1'];
});

it('takes a result from entry to publication, with evidence and every step on record', function () {
    $officer = actingAsRole('mel_officer', name: 'Otto Officer');
    $this->post(mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/indicators/{$this->indicator->id}/units/{$this->units['JUB']->id}"), ['value' => '1,200'])
        ->assertSessionHasNoErrors();
    $submission = inAcme(fn () => Submission::query()->sole());
    expect($submission->status)->toBe(Submission::DRAFT)->and($submission->value)->toBe('1200.000000');

    $this->post(mel("submissions/{$submission->id}/evidence"), ['file' => UploadedFile::fake()->create('register.pdf', 40, 'application/pdf'), 'share_with_donors' => true])
        ->assertSessionHasNoErrors();
    $this->post(mel("submissions/{$submission->id}/submit"))->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(Submission::SUBMITTED);

    // Evidence of a submitted result is fixed.
    $this->post(mel("submissions/{$submission->id}/evidence"), ['file' => UploadedFile::fake()->create('late.pdf', 10)])->assertSessionHasErrors('file');

    // The submitter does not verify their own result.
    $this->post(mel("submissions/{$submission->id}/verify"))->assertSessionHasErrors('submission');

    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$submission->id}/review"))->assertSessionHasNoErrors();
    $this->post(mel("submissions/{$submission->id}/verify"), ['comment' => 'Matches the register'])->assertSessionHasNoErrors();
    $this->post(mel("submissions/{$submission->id}/publish"))->assertSessionHasNoErrors();

    $submission->refresh();
    expect($submission->status)->toBe(Submission::PUBLISHED);
    $verified = inAcme(fn () => VerifiedResult::query()->sole());
    expect($verified->value)->toBe('1200.000000')->and($verified)->verified_by->toBe($this->manager->id)->published_by->toBe($this->manager->id);
    expect(inAcme(fn () => ReviewDecision::query()->where('submission_id', $submission->id)->orderBy('created_at')->pluck('action')->all()))
        ->toBe(['submitted', 'review_started', 'verified', 'published']);
    expect(inAcme(fn () => AuditEvent::query()->where('subject_type', 'submission')->pluck('action')->all()))
        ->toContain('submission.started', 'submission.submitted', 'submission.verified', 'submission.published');
});

it('separates verification from publication when the organization asks', function () {
    $this->put(mel('settings'), [])->assertForbidden();
    actingAsRole('mel_administrator', name: 'Ada Admin');
    $this->put(mel('settings'), ['verifier_must_differ' => true, 'publisher_must_differ' => true, 'on_track_percent' => 90, 'attention_percent' => 70])->assertSessionHasNoErrors();

    actingAsRole('mel_officer');
    $submission = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10']);

    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$submission->id}/verify"))->assertSessionHasNoErrors();
    $this->post(mel("submissions/{$submission->id}/publish"))->assertSessionHasErrors('submission');
    expect($submission->fresh()->status)->toBe(Submission::VERIFIED);

    actingAsRole('mel_manager');
    $this->post(mel("submissions/{$submission->id}/publish"))->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(Submission::PUBLISHED);
});

it('sends a result back with a reason, and takes the correction', function () {
    actingAsRole('mel_officer', name: 'Otto Officer');
    $submission = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10']);

    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$submission->id}/reject"), ['comment' => ''])->assertSessionHasErrors('comment');
    $this->post(mel("submissions/{$submission->id}/reject"), ['comment' => 'Register shows 12'])->assertSessionHasNoErrors();
    expect($submission->fresh()->status)->toBe(Submission::REJECTED);

    actingAsRole('mel_officer');
    $this->get(mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/indicators/{$this->indicator->id}/units/{$this->units['JUB']->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('submission.sentBack', 'Register shows 12'));
    enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '12']);

    $submission->refresh();
    expect($submission)->status->toBe(Submission::SUBMITTED)->value->toBe('12.000000')->revision->toBe(1);
});

it('refuses a second result for the same indicator, period and unit while one is in review', function () {
    actingAsRole('mel_officer');
    enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10']);

    $this->post(mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/indicators/{$this->indicator->id}/units/{$this->units['JUB']->id}"), ['value' => '11'])
        ->assertSessionHasErrors('value');
    expect(inAcme(fn () => Submission::query()->count()))->toBe(1);
});

it('keeps a verified value until its correction is verified', function () {
    actingAsRole('mel_officer');
    $first = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10']);
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$first->id}/verify"));
    $this->post(mel("submissions/{$first->id}/publish"));

    actingAsRole('mel_officer');
    $correction = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '14']);
    expect($correction)->revision->toBe(2)->supersedes_id->toBe($first->id);
    expect($first->fresh()->status)->toBe(Submission::PUBLISHED);
    expect(inAcme(fn () => VerifiedResult::query()->standing()->sole()->value))->toBe('10.000000');

    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$correction->id}/verify"))->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe(Submission::SUPERSEDED);
    $standing = inAcme(fn () => VerifiedResult::query()->standing()->sole());
    expect($standing->value)->toBe('14.000000')->and($standing->published_at)->toBeNull();
    // The first verified value is still on record.
    expect(inAcme(fn () => VerifiedResult::query()->where('submission_id', $first->id)->sole()->superseded_at))->not->toBeNull();
});

it('asks why a value is off target before it is submitted', function () {
    $this->put(mel("programmes/{$this->programme->id}/indicators/{$this->indicator->id}/targets"), [
        'unit_id' => $this->units['JUB']->id, 'rows' => [['period_id' => $this->q1->id, 'value' => '100']],
    ])->assertSessionHasNoErrors();

    actingAsRole('mel_officer');
    $path = mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/indicators/{$this->indicator->id}/units/{$this->units['JUB']->id}");
    $this->post($path, ['value' => '40', 'submit' => true])->assertSessionHasErrors('deviation_note');
    // Saved together or not at all.
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);
    $this->post($path, ['value' => '40', 'deviation_note' => 'Rains delayed drilling', 'submit' => true])->assertSessionHasNoErrors();
});

it('checks disaggregation against the total by the breakdown\'s rule', function () {
    $sex = inAcme(fn () => Dimension::query()->where('name', 'Sex')->with('categories')->sole());
    $indicator = addIndicator($this->programme, $this->output, 'OP2', ['breakdowns' => [['dimension_id' => $sex->id, 'rule' => 'must_equal_total']]]);
    $breakdown = inAcme(fn () => $indicator->fresh()->currentVersion->breakdowns->sole());
    [$female, $male] = $sex->categories->all();

    actingAsRole('mel_officer');
    $path = mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/indicators/{$indicator->id}/units/{$this->units['JUB']->id}");
    $cells = fn (string $f, string $m) => [
        ['breakdown_id' => $breakdown->id, 'category_id' => $female->id, 'value' => $f],
        ['breakdown_id' => $breakdown->id, 'category_id' => $male->id, 'value' => $m],
    ];

    $this->post($path, ['value' => '100', 'cells' => $cells('60', '30'), 'submit' => true])->assertSessionHasErrors('cells');
    $this->post($path, ['value' => '100', 'cells' => $cells('60', '40'), 'submit' => true])->assertSessionHasNoErrors();
    expect(inAcme(fn () => Submission::query()->where('indicator_id', $indicator->id)->sole()->values()->count()))->toBe(2);
});

it('keeps values under the definition they were reported with', function () {
    actingAsRole('mel_officer');
    $submission = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10']);
    $first = $submission->indicator_version_id;

    actingAsRole('mel_manager', $this->manager);
    $definition = [
        'result_id' => $this->output->id, 'code' => 'OP1', 'frequency' => 'quarterly',
        'name' => 'People reached with safe water', 'type' => 'output', 'measure' => 'count', 'decimals' => 0,
        'direction' => 'increase', 'time_aggregation' => 'sum', 'unit_aggregation' => 'sum',
    ];
    $this->put(mel("programmes/{$this->programme->id}/indicators/{$this->indicator->id}"), $definition)->assertSessionHasErrors('change_reason');
    $this->put(mel("programmes/{$this->programme->id}/indicators/{$this->indicator->id}"), [...$definition, 'change_reason' => 'Donor wording'])->assertSessionHasNoErrors();

    $versions = inAcme(fn () => IndicatorVersion::query()->where('indicator_id', $this->indicator->id)->orderBy('version')->get());
    expect($versions->pluck('name')->all())->toBe(['People with access to safe water', 'People reached with safe water'])
        ->and($submission->fresh()->indicator_version_id)->toBe($first);

    // The measure stays once results exist.
    $this->put(mel("programmes/{$this->programme->id}/indicators/{$this->indicator->id}"), [...$definition, 'measure' => 'percentage', 'time_aggregation' => 'recompute', 'unit_aggregation' => 'recompute', 'change_reason' => 'x'])
        ->assertSessionHasErrors('measure');
});

it('keeps every baseline and target change with its reason', function () {
    $path = mel("programmes/{$this->programme->id}/indicators/{$this->indicator->id}");
    $this->post("{$path}/baseline", ['value' => '500', 'measured_on' => '2025-12-01'])->assertSessionHasNoErrors();
    $this->post("{$path}/baseline", ['value' => '520', 'measured_on' => '2025-12-01'])->assertSessionHasErrors('change_reason');
    $this->post("{$path}/baseline", ['value' => '520', 'measured_on' => '2025-12-01', 'change_reason' => 'Survey re-weighted'])->assertSessionHasNoErrors();

    $this->put("{$path}/targets", ['rows' => [['period_id' => $this->q1->id, 'value' => '1000']]])->assertSessionHasNoErrors();
    $this->put("{$path}/targets", ['rows' => [['period_id' => $this->q1->id, 'value' => '1000']]])->assertSessionHasNoErrors();
    $this->put("{$path}/targets", ['rows' => [['period_id' => $this->q1->id, 'value' => '1200']], 'change_reason' => 'Extra funding'])->assertSessionHasNoErrors();

    $this->get($path)->assertInertia(fn (Assert $page) => $page
        ->has('baselines', 2)
        ->where('baselines.0.value', '520.000000')->where('baselines.0.live', true)->where('baselines.0.changeReason', 'Survey re-weighted')
        ->where('baselines.1.live', false)
        ->has('targets', 2)
        ->where('targets.0.value', '1200.000000'));
});

it('lets a draft be thrown away, never a submitted result', function () {
    actingAsRole('mel_officer');
    $draft = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10'], submit: false);
    $this->post(mel("submissions/{$draft->id}/evidence"), ['file' => UploadedFile::fake()->create('photo.jpg', 10)])->assertSessionHasNoErrors();
    $path = inAcme(fn () => EvidenceFile::query()->sole()->path);

    $this->delete(mel("submissions/{$draft->id}"))->assertRedirect();
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);
    Storage::disk('local')->assertMissing($path);

    $submitted = enterResult($this->programme, $this->q1, $this->indicator, $this->units['BOR'], ['value' => '10']);
    $this->delete(mel("submissions/{$submitted->id}"))->assertSessionHasErrors('submission');
});

it('refuses uploads a browser would run', function () {
    actingAsRole('mel_officer');
    $draft = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '10'], submit: false);

    $this->post(mel("submissions/{$draft->id}/evidence"), ['file' => UploadedFile::fake()->create('page.html', 1, 'text/html')])->assertSessionHasErrors('file');
    $this->post(mel("submissions/{$draft->id}/evidence"), ['file' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('file');
});

it('takes results only for active indicators, open periods and the indicator\'s own frequency', function () {
    actingAsRole('mel_officer');
    $path = fn ($period) => mel("programmes/{$this->programme->id}/periods/{$period->id}/indicators/{$this->indicator->id}/units/{$this->units['JUB']->id}");

    actingAsRole('mel_manager', $this->manager);
    $this->put(mel("programmes/{$this->programme->id}/periods/{$this->q1->id}/status"), ['status' => 'closed'])->assertSessionHasNoErrors();
    $this->post(mel("programmes/{$this->programme->id}/periods/generate"), ['frequency' => 'annual', 'due_after_days' => 30])->assertSessionHasNoErrors();
    $year = inAcme(fn () => $this->programme->periods()->where('frequency', 'annual')->sole());

    actingAsRole('mel_officer');
    $this->post($path($this->q1), ['value' => '1'])->assertSessionHasErrors('value');
    $this->post($path($year), ['value' => '1'])->assertSessionHasErrors('value');
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);
});
