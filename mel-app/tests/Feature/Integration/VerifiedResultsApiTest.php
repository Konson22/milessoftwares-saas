<?php

use App\Mel\Models\Indicator;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Submission;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| What Projects reads from M&E (ADR-0019 §20): the published, standing
| results of the units linked to one project, for a partner report.
*/

const PROJECT_ID = '0198a3b2-0000-7000-8000-00000000aaaa';
const OTHER_PROJECT_ID = '0198a3b2-0000-7000-8000-00000000bbbb';

beforeEach(function () {
    Storage::fake('local');
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'periods' => $this->periods, 'output' => $this->output] = setUpProgramme();
    $this->indicator = addIndicator($this->programme, $this->output, 'OP1');
    inAcme(fn () => ProgrammeUnit::query()->whereKey($this->units['JUB']->id)->update(['linked_project_id' => PROJECT_ID, 'shares_with_project_reports' => true]));
    inAcme(fn () => ProgrammeUnit::query()->whereKey($this->units['BOR']->id)->update(['linked_project_id' => OTHER_PROJECT_ID, 'shares_with_project_reports' => true]));
});

/**
 * A value entered by an officer and taken by the manager as far as $stage
 * (submitted, verified or published).
 */
function resultAt(string $stage, ReportingPeriod $period, ProgrammeUnit $unit, string $value, array $evidence = []): Submission
{
    actingAsRole('mel_officer');
    $submission = enterResult(test()->programme, $period, test()->indicator, $unit, ['value' => $value], submit: false);
    foreach ($evidence as $name => $shared) {
        test()->post(mel("submissions/{$submission->id}/evidence"), ['file' => UploadedFile::fake()->create($name, 5, 'application/pdf'), 'share_with_donors' => $shared])->assertSessionHasNoErrors();
    }
    test()->post(mel("submissions/{$submission->id}/submit"))->assertSessionHasNoErrors();
    actingAsRole('mel_manager', test()->manager);
    if ($stage !== 'submitted') {
        test()->post(mel("submissions/{$submission->id}/verify"))->assertSessionHasNoErrors();
    }
    if ($stage === 'published') {
        test()->post(mel("submissions/{$submission->id}/publish"))->assertSessionHasNoErrors();
    }

    return $submission;
}

/**
 * @param  array{source?: string, org?: string, bare?: bool}  $o
 */
function projectsRead(string $path, array $o = []): TestResponse
{
    $token = test()->platform()->integrationToken($o['org'] ?? test()->platform()->organization('acme')['id'], $o['source'] ?? 'projects', 'mel');

    // Projects always asks for a report period; the whole of 2026 unless the test says otherwise.
    if (! str_contains($path, '?') && ! ($o['bare'] ?? false)) {
        $path .= '?from=2026-01-01&to=2026-12-31';
    }

    return test()->withToken($token)->getJson(mel('integration/v1/'.$path));
}

it('gives the published results of the units linked to the project, and nothing else', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '120', ['register.pdf' => true, 'internal-notes.pdf' => false]);
    resultAt('verified', $this->periods['2026 Q2'], $this->units['JUB'], '80');
    resultAt('submitted', $this->periods['2026 Q3'], $this->units['JUB'], '60');
    resultAt('published', $this->periods['2026 Q1'], $this->units['BOR'], '999');

    $response = projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()
        ->assertJsonPath('linked', true)
        ->assertJsonPath('units', 1)
        ->assertJsonPath('truncated', false)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.indicatorCode', 'OP1')
        ->assertJsonPath('data.0.indicatorId', $this->indicator->id)
        ->assertJsonPath('data.0.unit', 'Juba')
        ->assertJsonPath('data.0.period', '2026 Q1')
        ->assertJsonPath('data.0.periodStartsOn', '2026-01-01')
        ->assertJsonPath('data.0.periodEndsOn', '2026-03-31')
        ->assertJsonPath('data.0.unitLabel', 'people')
        ->assertJsonPath('data.0.value', '120.000000')
        ->assertJsonPath('data.0.evidence', [['name' => 'register.pdf', 'mimeType' => 'application/pdf', 'size' => 5 * 1024]]);

    expect($response->json('data.0'))->not->toHaveKeys(['cells', 'narrative', 'submissionId', 'path', 'verifiedBy']);
    expect($response->json('data.0.publishedAt'))->not->toBeNull();
});

it('keeps to the periods that overlap the report period, both ends included', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    resultAt('published', $this->periods['2026 Q2'], $this->units['JUB'], '20');
    resultAt('published', $this->periods['2026 Q3'], $this->units['JUB'], '30');

    $periods = fn (string $query) => array_column(projectsRead('projects/'.PROJECT_ID.'/verified-results?'.$query)->assertOk()->json('data'), 'period');

    expect($periods('from=2026-03-31&to=2026-04-01'))->toBe(['2026 Q1', '2026 Q2'])
        ->and($periods('from=2026-04-01&to=2026-06-30'))->toBe(['2026 Q2'])
        ->and($periods('from=2026-10-01&to=2026-12-31'))->toBe([])
        ->and($periods('from=2026-01-01&to=2026-12-31'))->toBe(['2026 Q1', '2026 Q2', '2026 Q3']);
});

it('sends the standing value only, once, after a correction is published', function () {
    $first = resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    $correction = resultAt('verified', $this->periods['2026 Q1'], $this->units['JUB'], '14');

    // The correction is verified, not yet published: the old value is superseded, so nothing is sent.
    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('linked', true);

    $this->post(mel("submissions/{$correction->id}/publish"))->assertSessionHasNoErrors();
    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.value', '14.000000');
    // Asked again: the same answer.
    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.value', '14.000000');
    expect($first->fresh()->status)->toBe(Submission::SUPERSEDED);
});

it('tells an unlinked project from a linked one with nothing published', function () {
    projectsRead('projects/0198a3b2-0000-7000-8000-00000000cccc/verified-results')->assertOk()->assertJsonPath('linked', false)->assertJsonPath('data', []);
    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonPath('linked', true)->assertJsonPath('data', []);
});

it('keeps results of a unit archived later', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    $this->post(mel("programmes/{$this->programme->id}/units/{$this->units['JUB']->id}/archive"))->assertSessionHasNoErrors();
    expect(inAcme(fn () => ProgrammeUnit::query()->find($this->units['JUB']->id)->archived_at))->not->toBeNull();

    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonCount(1, 'data');
});

it('reads only the organization the platform signed for', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    provision('globex');

    projectsRead('projects/'.PROJECT_ID.'/verified-results', ['org' => $this->platform()->organization('globex')['id']])
        ->assertOk()->assertJsonPath('linked', false)->assertJsonPath('data', []);
    projectsRead('projects/'.PROJECT_ID.'/verified-results?from=2026-01-01&to=2026-12-31&organization_id='.$this->platform()->organization('acme')['id'], ['org' => $this->platform()->organization('globex')['id']])
        ->assertOk()->assertJsonPath('linked', false);
});

it('refuses reads it should not answer', function () {
    projectsRead('projects/'.PROJECT_ID.'/verified-results', ['source' => 'finance'])->assertForbidden()->assertJsonPath('error', 'source_not_allowed');
    $this->withToken('nonsense')->getJson(mel('integration/v1/projects/'.PROJECT_ID.'/verified-results'))->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    projectsRead('projects/not-a-uuid/verified-results')->assertStatus(422)->assertJsonPath('error', 'invalid');
    projectsRead('projects/'.PROJECT_ID.'/verified-results?from=2026-01-01')->assertStatus(422);
    projectsRead('projects/'.PROJECT_ID.'/verified-results', ['bare' => true])->assertStatus(422);
    projectsRead('projects/'.PROJECT_ID.'/verified-results?from=2026-06-01&to=2026-01-01')->assertStatus(422);

    $this->platform()->organization('newco');
    projectsRead('projects/'.PROJECT_ID.'/verified-results', ['org' => $this->platform()->organization('newco')['id']])->assertNotFound()->assertJsonPath('error', 'not_provisioned');

    $this->platform()->organization('frozen', ['status' => 'suspended']);
    projectsRead('projects/'.PROJECT_ID.'/verified-results', ['org' => $this->platform()->organization('frozen')['id']])->assertForbidden()->assertJsonPath('error', 'organization_inactive');
});

it('sends nothing from a linked unit nobody has set to report to the project', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    inAcme(fn () => ProgrammeUnit::query()->whereKey($this->units['JUB']->id)->update(['shares_with_project_reports' => false]));

    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()
        ->assertJsonPath('linked', true)->assertJsonPath('units', 1)->assertJsonPath('shared', 0)->assertJsonPath('data', []);
});

it('sends nothing for an indicator that is still a draft', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');
    inAcme(fn () => Indicator::query()->whereKey($this->indicator->id)->update(['status' => Indicator::DRAFT]));

    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()->assertJsonPath('shared', 1)->assertJsonPath('data', []);
});

it('says who each result is released to in the project reports, the owner only by default', function () {
    resultAt('published', $this->periods['2026 Q1'], $this->units['JUB'], '10');

    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()
        ->assertJsonPath('data.0.audience', 'owner')->assertJsonPath('data.0.partnerOrganizationId', null);

    $partner = '0198a3b2-0000-7000-8000-0000000000d1';
    inAcme(fn () => ProgrammeUnit::query()->whereKey($this->units['JUB']->id)->update(['project_report_audience' => 'donors', 'project_report_partner_organization_id' => $partner]));

    projectsRead('projects/'.PROJECT_ID.'/verified-results')->assertOk()
        ->assertJsonPath('data.0.audience', 'donors')->assertJsonPath('data.0.partnerOrganizationId', $partner);
});

it('refuses an unknown audience, or a partner audience without a partner, in the database', function () {
    $unit = $this->units['JUB']->id;
    expect(fn () => inAcme(fn () => ProgrammeUnit::query()->whereKey($unit)->update(['project_report_audience' => 'everyone'])))->toThrow(QueryException::class);
    expect(fn () => inAcme(fn () => ProgrammeUnit::query()->whereKey($unit)->update(['project_report_audience' => 'partner'])))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL constraints');
