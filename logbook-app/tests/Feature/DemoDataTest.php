<?php

use App\Logbook\Models\Dispatch;
use App\Logbook\Models\Entry;
use App\Logbook\Models\ResourceAssignment;
use App\Logbook\Models\Track;
use Miles\PlatformClient\Tenancy\CurrentTenant;

it('seeds a workspace with demo data once, through the domain rules', function () {
    $owner = actingAsRole('logbook_manager', name: 'Owner');
    $organizationId = inTenant(fn () => app(CurrentTenant::class)->id());

    $this->artisan('logbook:demo-data', ['organization' => $organizationId, '--days' => 3])
        ->expectsOutputToContain('Demo data seeded.')
        ->assertSuccessful();

    inTenant(function () {
        expect(Entry::query()->whereNotNull('reference')->count())->toBeGreaterThan(5)
            ->and(Track::query()->where('status', 'ended')->where('distance_m', '>', 1000)->exists())->toBeTrue();
        // ADR-0032: drivers assigned, trips planned (done, ahead, cancelled), trips with their stops.
        expect(ResourceAssignment::query()->count())->toBe(4)
            ->and(Dispatch::query()->where('status', 'planned')->count())->toBe(3)
            ->and(Dispatch::query()->where('status', 'cancelled')->count())->toBe(1)
            ->and(Entry::query()->whereNotNull('stops')->whereNotNull('activity_ended_at')->exists())->toBeTrue();
        $done = Dispatch::query()->where('status', 'done')->first();
        if ($done !== null) {
            expect(Entry::query()->whereKey($done->log_entry_id)->value('dispatch_id'))->toBe($done->id);
        }
    });

    // The dashboard's charts read it: two weeks of days, the week's schedules, open work by status.
    $this->get(p())->assertInertia(fn ($page) => $page->component('home')
        ->has('charts.perDay', 14)
        ->where('charts.perDay.13.entries', fn ($n) => $n >= 0)
        ->where('charts.compliance', fn ($c) => collect($c)->sum() > 0)
        ->has('charts.tasks.overdue')
        ->has('charts.severity.critical'));

    // The Tasks page's charts: two weeks of flow, overdue plus the next 7 days, workload per person.
    $this->get(p('tasks?tab=all'))->assertInertia(fn ($page) => $page->component('tasks/index')
        ->has('charts.flow', 14)
        ->has('charts.due', 8)
        ->where('charts.due.0.label', 'Overdue')
        ->where('charts.workload', fn ($rows) => count($rows) > 1)
        ->where('charts.priority', fn ($p) => collect($p)->sum() > 0));
    // No workload on one's own tasks, and no charts on recurring work.
    $this->get(p('tasks'))->assertInertia(fn ($page) => $page->where('charts.workload', []));
    $this->get(p('tasks?tab=recurring'))->assertInertia(fn ($page) => $page->where('charts', null));

    // The Exceptions page's figures: open exceptions per severity.
    $this->get(p('exceptions'))->assertInertia(fn ($page) => $page->component('exceptions/index')
        ->has('openBySeverity', 4)
        ->where('openBySeverity', fn ($s) => collect($s)->sum() > 0));

    // Permissions are open only while seeding: afterwards a viewer still cannot make a logbook.
    as_($owner, 'logbook_viewer');
    $this->post(p('logbooks'), ['name' => 'Not allowed'])->assertForbidden();

    // Once only.
    $this->artisan('logbook:demo-data', ['organization' => $organizationId, '--days' => 3])
        ->expectsOutputToContain('already has the demo data')
        ->assertFailed();
});
