<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Support\MelEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| M&E keeps a read-only copy of each project (ADR-0011).
| projects:publish-to-mel sends mel.project.updated for the projects whose
| snapshot changed; nothing in the way projects are kept knows about M&E.
| Without M&E the outbox skips the event, and the organization is probed
| with one project a day until one gets through.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00'));
    // Delivery is the outbox's job, tested in platform-client.
    Queue::fake();
    config(['platform-client.modules.mel' => 'http://mel.test/mel/events']);
    $this->owner = $this->member('acme', ['projects.view']);
});

function melProject(string $code, string $name = 'WASH Juba'): Project
{
    return test()->asTenant('acme', function () use ($code, $name) {
        $project = new Project(['code' => $code, 'name' => $name, 'currency' => 'USD', 'start_on' => '2026-01-01', 'due_on' => '2026-12-31']);
        $project->forceFill(['status' => 'active', 'budget_minor' => 5_000_000, 'created_by' => test()->owner->id])->save();

        return $project;
    });
}

/**
 * @return list<array{status: string, payload: array<string, mixed>}>
 */
function melEvents(): array
{
    return DB::table('platform_outbox')->where('audience', 'mel')->orderBy('id')->get()
        ->map(fn (object $row) => ['status' => (string) $row->status, 'payload' => json_decode((string) $row->payload, true)])
        ->all();
}

function markMelEvents(string $status): void
{
    DB::table('platform_outbox')->where('audience', 'mel')->update(['status' => $status]);
}

it('sends nothing until M&E has an events URL', function () {
    config(['platform-client.modules.mel' => null]);
    melProject('WASH');

    $this->artisan('projects:publish-to-mel')->expectsOutputToContain('PLATFORM_MEL_EVENTS_URL is not set')->assertSuccessful();

    expect(melEvents())->toBe([]);
});

it('sends a snapshot of the project and its milestones, not its tasks or spending lines', function () {
    $project = melProject('WASH');
    $milestone = $this->asTenant('acme', fn () => Milestone::query()->create(['project_id' => $project->id, 'name' => 'Boreholes drilled', 'due_on' => '2026-06-30']));

    $this->artisan('projects:publish-to-mel')->expectsOutputToContain('acme: 1 sent')->assertSuccessful();

    $row = DB::table('platform_outbox')->where('audience', 'mel')->sole();
    expect($row->event_type)->toBe(MelEvents::PROJECT_UPDATED)
        ->and($row->schema_version)->toBe(1)
        ->and($row->organization_id)->toBe($this->organization('acme')->id)
        ->and(json_decode($row->payload, true))->toBe([
            'project_id' => $project->id,
            'code' => 'WASH',
            'name' => 'WASH Juba',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'progress_percent' => 0,
            'currency' => 'USD',
            'approved_budget_minor' => 5_000_000,
            'spent_minor' => 0,
            'milestones' => [
                ['milestone_id' => $milestone->id, 'name' => 'Boreholes drilled', 'due_on' => '2026-06-30', 'reached_on' => null],
            ],
        ]);
});

it('probes with one project, then sends the rest, then only what changed', function () {
    $wash = melProject('WASH');
    melProject('EDU', 'Schools Bor');

    // First run: one project, as a probe.
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(melEvents())->toHaveCount(1);

    // It got through: the rest go.
    markMelEvents('delivered');
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(melEvents())->toHaveCount(2);

    // Nothing changed: nothing sent.
    markMelEvents('delivered');
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(melEvents())->toHaveCount(2);

    // A change to one project sends that one.
    $this->asTenant('acme', fn () => $wash->forceFill(['name' => 'WASH Juba and Bor'])->save());
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    $events = melEvents();
    expect($events)->toHaveCount(3)
        ->and($events[2]['payload']['name'])->toBe('WASH Juba and Bor');
});

it('waits a day after a skip, then probes again and resends what was skipped', function () {
    melProject('WASH');
    melProject('EDU', 'Schools Bor');

    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    // The organization has no M&E: the outbox skipped it.
    markMelEvents('skipped');

    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(melEvents())->toHaveCount(1);

    // A day later: one probe, unchanged as it is.
    $this->travel(25)->hours();
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(melEvents())->toHaveCount(2);

    // M&E was added: the probe got through, so both projects arrive.
    DB::table('platform_outbox')->where('audience', 'mel')->orderByDesc('id')->limit(1)->update(['status' => 'delivered']);
    $this->artisan('projects:publish-to-mel')->assertSuccessful();
    expect(collect(melEvents())->pluck('payload.code')->sort()->values()->all())->toBe(['EDU', 'EDU', 'WASH']);
});
