<?php

use App\Models\LinkedMilestone;
use App\Models\LinkedProject;
use App\Models\ProgrammeUnit;
use Illuminate\Support\Str;

/*
| Projects sends mel.project.updated: a snapshot of one project, applied
| once through the inbox. M&E keeps a read-only copy that a programme's
| unit can link to; it never copies tasks, people or spending lines.
*/

beforeEach(function () {
    // No time travel: event tokens are checked against the real clock.
    $this->publishEventKeys();
    provision();
    $this->projectId = (string) Str::uuid();
    $this->milestoneIds = [(string) Str::uuid(), (string) Str::uuid()];
});

function snapshot(array $overrides = []): array
{
    return $overrides + [
        'project_id' => test()->projectId,
        'code' => 'WASH',
        'name' => 'WASH Juba',
        'status' => 'active',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'progress_percent' => 40,
        'currency' => 'USD',
        'approved_budget_minor' => 5_000_000,
        'spent_minor' => 1_250_000,
        'milestones' => [
            ['milestone_id' => test()->milestoneIds[0], 'name' => 'Boreholes drilled', 'due_on' => '2026-06-30', 'reached_on' => null],
            ['milestone_id' => test()->milestoneIds[1], 'name' => 'Handover', 'due_on' => '2026-12-15', 'reached_on' => null],
        ],
    ];
}

function sendSnapshot(array $payload, ?string $occurredAt = null, ?string $transaction = null)
{
    return test()->sendEvent('mel.project.updated', $transaction ?? 'project:'.$payload['project_id'].':'.Str::uuid(), $payload, occurredAt: $occurredAt);
}

it('keeps a copy of a project and its milestones, once per transaction', function () {
    sendSnapshot(snapshot(), transaction: 'project:1')->assertCreated();
    sendSnapshot(snapshot(), transaction: 'project:1')->assertOk()->assertJsonPath('status', 'duplicate');

    $project = inAcme(fn () => LinkedProject::query()->with('milestones')->sole());
    expect($project->external_id)->toBe($this->projectId)
        ->and($project->label())->toBe('WASH · WASH Juba')
        ->and($project->progress_percent)->toBe(40)
        ->and($project->approved_budget_minor)->toBe(5_000_000)
        ->and($project->spent_minor)->toBe(1_250_000)
        ->and($project->milestones->pluck('name')->all())->toBe(['Boreholes drilled', 'Handover']);
});

it('updates the copy, drops milestones Projects removed, and ignores an older snapshot', function () {
    sendSnapshot(snapshot(), now()->subMinutes(10)->toIso8601String())->assertCreated();

    $newer = snapshot([
        'status' => 'completed',
        'progress_percent' => 100,
        'milestones' => [['milestone_id' => $this->milestoneIds[0], 'name' => 'Boreholes drilled', 'due_on' => '2026-06-30', 'reached_on' => '2026-06-20']],
    ]);
    sendSnapshot($newer, now()->subMinutes(5)->toIso8601String())->assertCreated();
    // Delivered late: older than the copy, so nothing changes.
    sendSnapshot(snapshot(['name' => 'Old name']), now()->subMinutes(8)->toIso8601String())->assertCreated();

    $project = inAcme(fn () => LinkedProject::query()->with('milestones')->sole());
    expect($project->status)->toBe('completed')
        ->and($project->name)->toBe('WASH Juba')
        ->and($project->progress_percent)->toBe(100)
        ->and($project->milestones)->toHaveCount(1)
        ->and($project->milestones[0]->reached_on?->toDateString())->toBe('2026-06-20');
});

it('lets a unit link to the copy, without copying the project into the programme', function () {
    sendSnapshot(snapshot())->assertCreated();
    actingAsRole('mel_manager');
    ['programme' => $programme, 'units' => $units] = setUpProgramme();
    $project = inAcme(fn () => LinkedProject::query()->sole());

    $this->put(mel("programmes/{$programme->id}/units/{$units['JUB']->id}/project"), ['linked_project_id' => $project->id])->assertSessionHasNoErrors();

    expect(inAcme(fn () => ProgrammeUnit::query()->whereKey($units['JUB']->id)->sole()->linked_project_id))->toBe($project->id)
        ->and(inAcme(fn () => LinkedProject::query()->count()))->toBe(1);
});

it('keeps a removed milestone that a result is tied to', function () {
    sendSnapshot(snapshot(), now()->subMinute()->toIso8601String())->assertCreated();
    actingAsRole('mel_manager');
    ['output' => $output] = setUpProgramme();
    $milestone = inAcme(fn () => LinkedMilestone::query()->where('external_id', $this->milestoneIds[1])->sole());
    inAcme(fn () => $output->forceFill(['linked_milestone_id' => $milestone->id])->save());

    sendSnapshot(snapshot(['milestones' => []]))->assertCreated();

    expect(inAcme(fn () => LinkedMilestone::query()->pluck('external_id')->all()))->toBe([$this->milestoneIds[1]]);
});

it('refuses what is not a project snapshot from Projects', function () {
    sendSnapshot(snapshot(['progress_percent' => 140]))->assertUnprocessable()->assertJsonPath('error', 'not_applicable');
    $this->sendEvent('mel.project.updated', 'x:1', snapshot(), source: 'restaurant')->assertUnprocessable()->assertJsonPath('error', 'unsupported_event');
    $this->sendEvent('mel.indicator.updated', 'x:2', ['a' => 1])->assertUnprocessable()->assertJsonPath('error', 'unsupported_event');
    $this->sendEvent('finance.invoice.issued', 'x:3', ['a' => 1])->assertUnprocessable()->assertJsonPath('error', 'wrong_module');

    expect(inAcme(fn () => LinkedProject::query()->count()))->toBe(0);
});

it('refuses a token for another organization or audience, and asks to retry before M&E is set up', function () {
    $this->sendEvent('mel.project.updated', 'x:1', snapshot(), token: $this->eventToken('acme', ['audience' => 'payroll']))->assertUnauthorized();
    $this->sendEvent('mel.project.updated', 'x:2', snapshot(), token: $this->eventToken('acme', ['org' => (string) Str::uuid()]))->assertForbidden();

    $this->sendEvent('mel.project.updated', 'x:3', snapshot(), slug: 'globex')->assertStatus(503)->assertHeader('Retry-After', '60');
});
