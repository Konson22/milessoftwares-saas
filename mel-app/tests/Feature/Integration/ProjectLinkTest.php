<?php

use App\Mel\Models\AuditEvent;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\Result;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Linking reporting units and results to Tatook Projects (ADR-0019 §20):
| M&E keeps the ids and reads the rest live from Projects' integration API,
| as the signed-in member.
*/

const PM_URL = 'http://pm.internal/projects/integration/v1';
const WASH = '0198a3b2-0000-7000-8000-0000000000a1';
const ROADS = '0198a3b2-0000-7000-8000-0000000000a2';
const WELLS = '0198a3b2-0000-7000-8000-0000000000c1';

beforeEach(function () {
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'output' => $this->output] = setUpProgramme();
    $this->pm = ['down' => false, 'status' => null, 'projects' => [
        WASH => ['code' => 'WASH', 'name' => 'Clean water', 'milestones' => [WELLS => ['name' => 'Wells drilled', 'dueOn' => '2027-03-01']]],
        ROADS => ['code' => 'ROAD', 'name' => 'Feeder roads', 'milestones' => ['0198a3b2-0000-7000-8000-0000000000c2' => ['name' => 'Bridge', 'dueOn' => '2027-05-01']]],
    ]];
});

/**
 * Projects, on: answers like project-management's integration API from
 * $this->pm (down: 503; status: that HTTP status for everything).
 */
function projectsOn(): void
{
    config(['platform-client.integrations.projects' => PM_URL]);
    Http::fake(['pm.internal/*' => function (Request $request) {
        $pm = test()->pm;
        if ($pm['down']) {
            return Http::response('', 503);
        }
        if ($pm['status'] !== null) {
            return Http::response(['error' => 'forbidden'], $pm['status']);
        }
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/projects/integration/v1/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $milestone = function (string $mid) use ($pm) {
            foreach ($pm['projects'] as $pid => $p) {
                if (isset($p['milestones'][$mid])) {
                    return ['id' => $mid, 'projectId' => $pid, 'projectCode' => $p['code'], 'name' => $p['milestones'][$mid]['name'], 'label' => $p['code'].' · '.$p['milestones'][$mid]['name'], 'dueOn' => $p['milestones'][$mid]['dueOn'], 'reachedOn' => null];
                }
            }

            return null;
        };
        $project = fn (string $id) => ['id' => $id, 'code' => $pm['projects'][$id]['code'], 'name' => $pm['projects'][$id]['name'], 'label' => $pm['projects'][$id]['code'].' · '.$pm['projects'][$id]['name'], 'status' => 'active', 'progressPercent' => 40, 'currency' => 'USD', 'approvedBudgetMinor' => null, 'paidMinor' => null, 'paidStatus' => null,
            'milestones' => array_map(fn ($mid) => $milestone($mid), array_keys($pm['projects'][$id]['milestones']))];

        if ($path === 'projects' && ! isset($query['ids'])) {
            return Http::response(['data' => array_map(fn ($id) => $project($id), array_keys($pm['projects']))]);
        }
        if ($path === 'projects') {
            $known = array_values(array_filter($query['ids'], fn ($id) => isset($pm['projects'][$id])));

            return Http::response(['data' => array_map($project, $known), 'missing' => array_values(array_diff($query['ids'], $known))]);
        }
        if (str_starts_with($path, 'projects/')) {
            $id = substr($path, 9);

            return isset($pm['projects'][$id]) ? Http::response(['data' => $project($id)]) : Http::response(['error' => 'unknown_project'], 404);
        }
        if ($path === 'milestones') {
            $all = [];
            foreach ($pm['projects'] as $pid => $p) {
                foreach (array_keys($p['milestones']) as $mid) {
                    if (in_array(isset($query['project_ids']) ? $pid : $mid, $query['project_ids'] ?? $query['ids'] ?? [], true)) {
                        $all[] = $milestone($mid);
                    }
                }
            }

            return Http::response(['data' => $all]);
        }

        return Http::response(['error' => 'not_found'], 404);
    }]);
}

function linkUnit(string $unit, ?string $project): TestResponse
{
    return test()->put(mel('programmes/'.test()->programme->id.'/units/'.test()->units[$unit]->id.'/project'), ['linked_project_id' => $project]);
}

function linkedTo(string $unit): ?string
{
    return inAcme(fn () => ProgrammeUnit::query()->findOrFail(test()->units[$unit]->id)->linked_project_id);
}

describe('M&E without Projects', function () {
    it('works as before, with no link offered and nothing read', function () {
        $this->get(mel("programmes/{$this->programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.linkProjects', false)
            ->where('options.projects', [])
            ->where('projectLink.status', 'ok'));
        linkUnit('JUB', WASH)->assertNotFound();
        expect($this->platform()->integrationTokens)->toBe([]);
    });

    it('keeps a milestone reference it cannot check, and never sets one', function () {
        inAcme(fn () => Result::query()->whereKey($this->output->id)->update(['linked_milestone_id' => WELLS]));
        $this->put(mel("programmes/{$this->programme->id}/results/{$this->output->id}"), resultForm($this->output, ['linked_milestone_id' => '']))->assertSessionHasNoErrors();
        expect(inAcme(fn () => $this->output->fresh()->linked_milestone_id))->toBe(WELLS);
    });
});

describe('M&E with Projects', function () {
    beforeEach(fn () => projectsOn());

    it('offers the member\'s projects and links a unit to one checked in Projects, once', function () {
        $this->get(mel("programmes/{$this->programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.linkProjects', true)
            ->where('options.projects', [['value' => WASH, 'label' => 'WASH · Clean water'], ['value' => ROADS, 'label' => 'ROAD · Feeder roads']]));

        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        linkUnit('JUB', WASH)->assertSessionHasNoErrors();

        expect(linkedTo('JUB'))->toBe(WASH)
            ->and(inAcme(fn () => AuditEvent::query()->where('action', 'unit.project_linked')->count()))->toBe(1)
            ->and(collect($this->platform()->integrationTokens)->pluck('acting_user')->unique()->all())->toBe([$this->manager->id])
            ->and(collect($this->platform()->integrationTokens)->pluck('audience')->unique()->all())->toBe(['projects']);

        $this->get(mel("programmes/{$this->programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('units.1.linkedProjectId', WASH)
            ->where('units.1.project.label', 'WASH · Clean water')
            ->where('units.1.project.milestones.0.name', 'Wells drilled')
            ->where('projectLink.status', 'ok'));
    });

    it('shares nothing with the project\'s reports until a manager says so, and stops when unlinked', function () {
        $shares = fn () => inAcme(fn () => ProgrammeUnit::query()->find($this->units['JUB']->id)->shares_with_project_reports);
        $url = mel('programmes/'.$this->programme->id.'/units/'.$this->units['JUB']->id.'/project');

        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        expect($shares())->toBeFalse();

        $this->put($url, ['linked_project_id' => WASH, 'shares_with_project_reports' => '1'])->assertSessionHasNoErrors();
        $this->put($url, ['linked_project_id' => WASH, 'shares_with_project_reports' => '1'])->assertSessionHasNoErrors();
        expect($shares())->toBeTrue()
            ->and(inAcme(fn () => AuditEvent::query()->where('action', 'unit.project_reporting_on')->count()))->toBe(1);

        linkUnit('JUB', null)->assertSessionHasNoErrors();
        expect($shares())->toBeFalse()->and(linkedTo('JUB'))->toBeNull();

        // Linking again starts unshared.
        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        expect($shares())->toBeFalse();
    });

    it('refuses a project Projects does not know or the member cannot see, and one already linked to another unit', function () {
        linkUnit('JUB', '0198a3b2-0000-7000-8000-0000000000ff')->assertSessionHasErrors(['linked_project_id' => 'That project is not in Projects, or you cannot see it there.']);
        expect(linkedTo('JUB'))->toBeNull();

        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        linkUnit('BOR', WASH)->assertSessionHasErrors('linked_project_id');
        expect(linkedTo('BOR'))->toBeNull();
    });

    it('says why it cannot link when the organization lacks Projects or the member has no access there', function () {
        $this->platform()->notEntitledTo = ['projects'];
        linkUnit('JUB', WASH)->assertSessionHasErrors(['linked_project_id' => 'Your organization does not have both Projects and M&E, so linked projects cannot be shown.']);
        $this->platform()->notEntitledTo = [];

        $this->pm['status'] = 403;
        linkUnit('JUB', WASH)->assertSessionHasErrors(['linked_project_id' => 'You do not have access to Projects, so linked projects cannot be shown.']);
        expect(linkedTo('JUB'))->toBeNull();
    });

    it('keeps links while Projects is down, says so, and changes nothing', function () {
        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        $this->pm['down'] = true;

        $this->get(mel("programmes/{$this->programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('units.1.linkedProjectId', WASH)
            ->where('units.1.project', null)
            ->where('projectLink.status', 'unavailable')
            ->where('projectLink.message', 'Projects cannot be reached right now. Linked projects are not shown; try again shortly.'));
        linkUnit('JUB', ROADS)->assertSessionHasErrors('linked_project_id');
        expect(linkedTo('JUB'))->toBe(WASH);

        // Unlinking needs nothing from Projects.
        linkUnit('JUB', null)->assertSessionHasNoErrors();
        expect(linkedTo('JUB'))->toBeNull();
    });

    it('keeps the link of a project no longer shown by Projects', function () {
        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        unset($this->pm['projects'][WASH]);

        $this->get(mel("programmes/{$this->programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('units.1.linkedProjectId', WASH)
            ->where('units.1.project', null)
            ->where('projectLink.status', 'ok'));
        expect(linkedTo('JUB'))->toBe(WASH);
    });

    it('points a result to a milestone of a linked project only, checked in Projects', function () {
        $url = mel("programmes/{$this->programme->id}/results/{$this->output->id}");
        $this->put($url, resultForm($this->output, ['linked_milestone_id' => WELLS]))->assertSessionHasErrors('linked_milestone_id');

        linkUnit('JUB', WASH)->assertSessionHasNoErrors();
        $this->get(mel("programmes/{$this->programme->id}/framework"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('options.milestones', [['value' => WELLS, 'label' => 'WASH · Wells drilled']]));
        $this->put($url, resultForm($this->output, ['linked_milestone_id' => '0198a3b2-0000-7000-8000-0000000000c2']))->assertSessionHasErrors('linked_milestone_id');
        $this->put($url, resultForm($this->output, ['linked_milestone_id' => WELLS]))->assertSessionHasNoErrors();
        expect(inAcme(fn () => $this->output->fresh()->linked_milestone_id))->toBe(WELLS);

        $this->get(mel("programmes/{$this->programme->id}/framework"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results.2.milestone', ['name' => 'Wells drilled', 'project' => 'WASH', 'dueOn' => '2027-03-01', 'reachedOn' => null]));

        // Projects down: the reference is shown as kept, saving it unchanged works.
        $this->pm['down'] = true;
        $this->get(mel("programmes/{$this->programme->id}/framework"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results.2.milestone', null)
            ->where('results.2.linkedMilestoneId', WELLS)
            ->where('options.milestones.0.value', WELLS));
        $this->put($url, resultForm($this->output, ['linked_milestone_id' => WELLS, 'statement' => 'Water points built and used']))->assertSessionHasNoErrors();
        expect(inAcme(fn () => $this->output->fresh()->linked_milestone_id))->toBe(WELLS);
    });
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function resultForm(Result $result, array $changes): array
{
    return [
        'level_id' => $result->level_id,
        'parent_id' => $result->parent_id,
        'code' => $result->code,
        'statement' => $result->statement,
        ...$changes,
    ];
}
