<?php

use App\Mel\Import;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    provision();
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'periods' => $this->periods, 'output' => $this->output] = setUpProgramme();
    $this->indicator = addIndicator($this->programme, $this->output, 'OP1');
    $this->rate = addIndicator($this->programme, $this->output, 'OP2', [
        'name' => 'Water points working', 'measure' => 'percentage', 'unit_label' => null, 'decimals' => 1,
        'time_aggregation' => 'latest', 'unit_aggregation' => 'recompute',
        'numerator_label' => 'Working', 'denominator_label' => 'Built',
    ]);
    $this->q1 = $this->periods['2026 Q1'];
});

function csvFile(string $name, array $rows): UploadedFile
{
    $lines = array_map(fn (array $row) => implode(',', array_map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"', $row)), [Import::COLUMNS, ...$rows]);

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines));
}

it('imports a sheet in two steps and never twice', function () {
    actingAsRole('mel_officer', name: 'Otto Officer');
    $this->get(mel("programmes/{$this->programme->id}/import/template/{$this->q1->id}"))->assertOk()->assertDownload();

    $rows = [
        ['OP1', '', 'JUB', '', '120', '', '', 'From the register'],
        ['OP1', '', 'BOR', '', '80', '', '', ''],
        ['OP2', '', 'JUB', '', '', '9', '10', ''],
        ['OP2', '', 'BOR', '', '', '', '', ''],
    ];
    $this->post(mel("programmes/{$this->programme->id}/import"), ['period_id' => $this->q1->id, 'file' => csvFile('q1.csv', $rows)])->assertSessionHasNoErrors();
    $batch = inAcme(fn () => ImportBatch::query()->sole());
    expect($batch)->status->toBe(ImportBatch::VALIDATED)->rows_ready->toBe(3)->rows_skipped->toBe(1);
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);

    // Only the uploader applies it.
    actingAsRole('mel_officer');
    $this->post(mel("programmes/{$this->programme->id}/import/{$batch->id}/apply"))->assertNotFound();

    actingAsRole('mel_officer', inAcme(fn () => User::query()->where('name', 'Otto Officer')->sole()));
    $this->post(mel("programmes/{$this->programme->id}/import/{$batch->id}/apply"), ['submit' => true])->assertSessionHasNoErrors();
    $submissions = inAcme(fn () => Submission::query()->with('unit', 'indicator')->get());
    expect($submissions)->toHaveCount(3)
        ->and($submissions->pluck('status')->unique()->all())->toBe([Submission::SUBMITTED])
        ->and($submissions->first(fn ($s) => $s->indicator->code === 'OP2')->only(['value', 'numerator', 'denominator']))->toBe(['value' => null, 'numerator' => '9.000000', 'denominator' => '10.000000']);

    // The same file again: every row is already in.
    $this->post(mel("programmes/{$this->programme->id}/import"), ['period_id' => $this->q1->id, 'file' => csvFile('q1-again.csv', $rows)]);
    $again = inAcme(fn () => ImportBatch::query()->latest('created_at')->where('file_name', 'q1-again.csv')->sole());
    expect($again)->rows_ready->toBe(0);
    expect(inAcme(fn () => Submission::query()->count()))->toBe(3);
});

it('rejects the whole sheet when a row is wrong, saying which', function () {
    actingAsRole('mel_officer');
    $this->post(mel("programmes/{$this->programme->id}/import"), ['period_id' => $this->q1->id, 'file' => csvFile('bad.csv', [
        ['OP1', '', 'JUB', '', '120', '', '', ''],
        ['OP9', '', 'JUB', '', '5', '', '', ''],
        ['OP1', '', 'XXX', '', '5', '', '', ''],
        ['OP1', '', 'BOR', '', 'lots', '', '', ''],
        ['OP2', '', 'BOR', '', '', '11', '10', ''],
    ])])->assertSessionHasNoErrors();

    $batch = inAcme(fn () => ImportBatch::query()->sole());
    expect($batch->status)->toBe(ImportBatch::FAILED)
        ->and(collect($batch->errors)->pluck('row')->all())->toBe([3, 4, 5, 6]);
    $this->post(mel("programmes/{$this->programme->id}/import/{$batch->id}/apply"))->assertSessionHasErrors();
    expect(inAcme(fn () => Submission::query()->count()))->toBe(0);
});

it('recomputes a percentage across units from its parts, and counts only verified data', function () {
    actingAsRole('mel_officer');
    $a = enterResult($this->programme, $this->q1, $this->rate, $this->units['JUB'], ['numerator' => '9', 'denominator' => '10']);
    $b = enterResult($this->programme, $this->q1, $this->rate, $this->units['BOR'], ['numerator' => '1', 'denominator' => '90']);

    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("submissions/{$a->id}/verify"));
    $row = fn () => collect($this->get(mel("reports/iptt?programme={$this->programme->id}"))->viewData('page')['props']['data']['rows'])->firstWhere('indicator.code', 'OP2')['periods'][0]['actual'];
    expect($row())->toMatchArray(['value' => '90.000000', 'reported' => 1, 'expected' => 2]);

    $this->post(mel("submissions/{$b->id}/verify"));
    // (9 + 1) / (10 + 90), not the average of 90% and 1.1%.
    expect($row())->toMatchArray(['value' => '10.000000', 'numerator' => '10.000000', 'denominator' => '100.000000', 'reported' => 2]);
});

it('exports reports as a spreadsheet', function () {
    foreach (['iptt', 'framework', 'target-actual', 'completeness', 'verification', 'programme'] as $report) {
        $this->get(mel("reports/{$report}?programme={$this->programme->id}"))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/show')->where('report.key', $report));
        $this->get(mel("reports/{$report}?format=xlsx&programme={$this->programme->id}"))->assertOk()->assertDownload();
    }
    $this->get(mel("reports/nope?programme={$this->programme->id}"))->assertNotFound();
});

it('renders every page for an administrator', function () {
    actingAsRole('mel_administrator');
    actingAsRole('mel_officer');
    $submission = enterResult($this->programme, $this->q1, $this->indicator, $this->units['JUB'], ['value' => '5']);
    actingAsRole('mel_administrator');

    $p = $this->programme->id;
    $pages = [
        '' => 'home',
        'programmes' => 'programmes/index',
        'programmes/create' => 'programmes/form',
        "programmes/{$p}" => 'programmes/show',
        "programmes/{$p}/edit" => 'programmes/form',
        'frameworks' => 'frameworks/index',
        "programmes/{$p}/framework" => 'frameworks/show',
        'indicators' => 'indicators/index',
        "programmes/{$p}/indicators/create" => 'indicators/form',
        "programmes/{$p}/indicators/{$this->indicator->id}" => 'indicators/show',
        "programmes/{$p}/indicators/{$this->indicator->id}/edit" => 'indicators/form',
        'data' => 'data/index',
        "data?programme={$p}&period={$this->q1->id}" => 'data/index',
        "programmes/{$p}/periods/{$this->q1->id}/indicators/{$this->indicator->id}/units/{$this->units['BOR']->id}" => 'data/entry',
        "programmes/{$p}/import" => 'data/import',
        "submissions/{$submission->id}" => 'submissions/show',
        'verification' => 'verification/index',
        'verification?stage=publish' => 'verification/index',
        'reports' => 'reports/index',
        'settings' => 'settings/index',
        'audit' => 'audit/index',
    ];

    foreach ($pages as $path => $component) {
        $this->get(mel($path))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }
    expect(inAcme(fn () => AuditEvent::query()->count()))->toBeGreaterThan(10);
});
