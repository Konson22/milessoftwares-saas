<?php

use App\Mel\Models\EvidenceFile;
use App\Mel\Models\ProgrammeAccess;
use App\Mel\Models\Submission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'periods' => $this->periods, 'output' => $this->output] = setUpProgramme();
    $this->indicator = addIndicator($this->programme, $this->output, 'OP1');
    $this->q1 = $this->periods['2026 Q1'];
    $this->q2 = $this->periods['2026 Q2'];

    // Q1 Juba: published, with one donor-shared and one internal file.
    // Q1 Bor: verified, not published. Q2 Juba: published.
    $this->officer = actingAsRole('mel_officer', name: 'Otto Officer');
    $this->published = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '100'], submit: false);
    $this->post(mel("submissions/{$this->published->id}/evidence"), ['file' => UploadedFile::fake()->create('shared.pdf', 5), 'share_with_donors' => true]);
    $this->post(mel("submissions/{$this->published->id}/evidence"), ['file' => UploadedFile::fake()->create('internal.pdf', 5)]);
    $this->post(mel("submissions/{$this->published->id}/submit"))->assertSessionHasNoErrors();
    $this->verifiedOnly = enterResult($this->programme, $this->q1, $this->indicator, $this->units['BOR'], ['value' => '50']);
    $this->later = enterResult($this->programme, $this->q2, $this->indicator, $this->units['JUB'], ['value' => '70']);

    actingAsRole('mel_manager', $this->manager);
    foreach ([$this->published, $this->verifiedOnly, $this->later] as $submission) {
        $this->post(mel("submissions/{$submission->id}/verify"))->assertSessionHasNoErrors();
    }
    $this->post(mel("submissions/{$this->published->id}/publish"))->assertSessionHasNoErrors();
    $this->post(mel("submissions/{$this->later->id}/publish"))->assertSessionHasNoErrors();

    $this->files = inAcme(fn () => EvidenceFile::query()->get()->keyBy('original_name'));
});

function grant(string $userId, array $scope = []): void
{
    actingAsRole('mel_manager', test()->manager);
    test()->post(mel('programmes/'.test()->programme->id.'/access'), ['user_id' => $userId, ...$scope])->assertSessionHasNoErrors();
}

/**
 * The IPTT's actual for OP1 in each period, by label.
 *
 * @return array<string, string|null>
 */
function ipttActuals(): array
{
    $response = test()->get(mel('reports/iptt?programme='.test()->programme->id))->assertOk();
    $row = collect($response->viewData('page')['props']['data']['rows'])->firstWhere('indicator.code', 'OP1');

    return collect($row['periods'] ?? [])->mapWithKeys(fn (array $cell) => [$cell['period'] => $cell['actual']['value'] ?? null])->all();
}

it('keeps each organization to its own workspace', function () {
    actingAsRole('mel_administrator', slug: 'globex');

    $this->get(mel("programmes/{$this->programme->id}", 'globex'))->assertNotFound();
    $this->get(mel("submissions/{$this->published->id}", 'globex'))->assertNotFound();
    $this->post(mel("submissions/{$this->verifiedOnly->id}/publish", 'globex'))->assertNotFound();
    $this->get(mel("evidence/{$this->files['shared.pdf']->id}", 'globex'))->assertNotFound();
    $this->get(mel('programmes', 'globex'))->assertInertia(fn (Assert $page) => $page->has('programmes', 0));
    $this->get(mel('reports/iptt?programme='.$this->programme->id, 'globex'))->assertNotFound();

    expect(inAcme(fn () => $this->verifiedOnly->fresh()->status))->toBe(Submission::VERIFIED);
});

it('shows a donor nothing until they are given the programme', function () {
    actingAsRole('mel_donor_viewer');

    $this->get(mel('programmes'))->assertInertia(fn (Assert $page) => $page->has('programmes', 0));
    $this->get(mel("programmes/{$this->programme->id}"))->assertNotFound();
    $this->get(mel('reports/iptt?programme='.$this->programme->id))->assertNotFound();
});

it('shows a donor published results only, and none of the workings', function () {
    $donor = actingAsRole('mel_donor_viewer', name: 'Dana Donor');
    grant($donor->id);
    actingAsRole('mel_donor_viewer', $donor);

    $this->get(mel("programmes/{$this->programme->id}"))->assertOk();
    // Juba alone in Q1: Bor's verified value is not published yet.
    expect(ipttActuals())->toMatchArray(['2026 Q1' => '100.000000', '2026 Q2' => '70.000000']);

    $this->get(mel("submissions/{$this->published->id}"))->assertOk();
    $this->get(mel("submissions/{$this->verifiedOnly->id}"))->assertNotFound();
    $this->get(mel("evidence/{$this->files['shared.pdf']->id}"))->assertOk();
    $this->get(mel("evidence/{$this->files['internal.pdf']->id}"))->assertNotFound();

    $this->get(mel('data'))->assertForbidden();
    $this->get(mel('verification'))->assertForbidden();
    $this->get(mel('reports/completeness?programme='.$this->programme->id))->assertForbidden();
    $this->get(mel('reports/iptt?format=xlsx&programme='.$this->programme->id))->assertForbidden();
    $this->post(mel("submissions/{$this->verifiedOnly->id}/publish"))->assertForbidden();
    $this->get(mel('audit'))->assertForbidden();
});

it('shows an evaluator verified results within their window', function () {
    $evaluator = actingAsRole('mel_evaluator', name: 'Eve Evaluator');
    grant($evaluator->id, ['periods_from' => '2026-01-01', 'periods_to' => '2026-03-31']);
    actingAsRole('mel_evaluator', $evaluator);

    // Bor's verified value counts; Q2 is outside the window.
    expect(ipttActuals())->toBe(['2026 Q1' => '150.000000']);
    $this->get(mel("submissions/{$this->verifiedOnly->id}"))->assertOk();
    $this->get(mel("submissions/{$this->later->id}"))->assertNotFound();
    $this->get(mel("evidence/{$this->files['internal.pdf']->id}"))->assertOk();
});

it('keeps one window per person and programme, so grants cannot widen each other', function () {
    $evaluator = actingAsRole('mel_evaluator');
    grant($evaluator->id, ['unit_id' => $this->units['JUB']->id]);

    $this->post(mel("programmes/{$this->programme->id}/access"), [
        'user_id' => $evaluator->id, 'unit_id' => $this->units['BOR']->id, 'periods_from' => '2026-01-01', 'periods_to' => '2026-03-31',
    ])->assertSessionHasErrors('periods_from');
    $this->post(mel("programmes/{$this->programme->id}/access"), ['user_id' => $evaluator->id, 'unit_id' => $this->units['BOR']->id])
        ->assertSessionHasNoErrors();
});

it('keeps a field officer to the units they are given', function () {
    $field = actingAsRole('mel_field_officer', name: 'Fred Field');
    grant($field->id, ['unit_id' => $this->units['BOR']->id]);
    actingAsRole('mel_field_officer', $field);

    $path = fn (string $unit) => mel("programmes/{$this->programme->id}/periods/{$this->periods['2026 Q3']->id}/indicators/{$this->indicator->id}/units/{$this->units[$unit]->id}");
    $this->get($path('BOR'))->assertOk();
    $this->get($path('JUB'))->assertNotFound();
    $this->post($path('JUB'), ['value' => '5'])->assertNotFound();
    $this->post($path('BOR'), ['value' => '5', 'submit' => true])->assertSessionHasNoErrors();

    $this->get(mel("submissions/{$this->published->id}"))->assertNotFound();
    $this->get(mel("submissions/{$this->verifiedOnly->id}"))->assertOk();
    $this->get(mel("evidence/{$this->files['internal.pdf']->id}"))->assertNotFound();

    // Field officers enter; they neither verify nor publish.
    $mine = inAcme(fn () => Submission::query()->where('submitted_by', $field->id)->sole());
    $this->post(mel("submissions/{$mine->id}/verify"))->assertForbidden();
    $this->get(mel('data?programme='.$this->programme->id.'&period='.$this->periods['2026 Q3']->id))
        ->assertInertia(fn (Assert $page) => $page->has('grid.units', 1)->where('grid.units.0.code', 'BOR'));
});

it('closes the programme when access is removed or expires', function () {
    $donor = actingAsRole('mel_donor_viewer');
    grant($donor->id);
    $grant = inAcme(fn () => ProgrammeAccess::query()->where('user_id', $donor->id)->sole());

    actingAsRole('mel_donor_viewer', $donor);
    $this->get(mel("programmes/{$this->programme->id}"))->assertOk();

    actingAsRole('mel_manager', $this->manager);
    $this->delete(mel("programmes/{$this->programme->id}/access/{$grant->id}"))->assertSessionHasNoErrors();

    actingAsRole('mel_donor_viewer', $donor);
    $this->get(mel("programmes/{$this->programme->id}"))->assertNotFound();
});

it('lets only the uploader remove a file, and only while the result is a draft', function () {
    actingAsRole('mel_officer', $this->officer);
    $this->delete(mel("evidence/{$this->files['internal.pdf']->id}"))->assertSessionHasErrors('file');

    $draft = enterResult($this->programme, $this->periods['2026 Q3'], $this->indicator, $this->units['JUB'], ['value' => '1'], submit: false);
    $this->post(mel("submissions/{$draft->id}/evidence"), ['file' => UploadedFile::fake()->create('mine.pdf', 5)]);
    $file = inAcme(fn () => EvidenceFile::query()->where('original_name', 'mine.pdf')->sole());

    actingAsRole('mel_officer');
    $this->delete(mel("evidence/{$file->id}"))->assertForbidden();
    actingAsRole('mel_officer', $this->officer);
    $this->delete(mel("evidence/{$file->id}"))->assertSessionHasNoErrors();
});
