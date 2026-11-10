<?php

use App\Actions\Spending;
use App\Enums\CostCategory;
use App\Enums\GrantStatus;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Downloads of project spending, budgets, grants and invoices, and search,
| each only for those allowed to see what it holds, in their organization.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->finance = $this->member('acme', ['projects.view', 'projects.finance.view', 'grants.view']);
    $this->plain = $this->member('acme', ['projects.view']);
});

function exportProject(string $slug = 'acme', string $code = 'WASH', ?User $by = null): Project
{
    return test()->asTenant($slug, function () use ($code, $by) {
        $project = new Project(['code' => $code, 'name' => "{$code} water project", 'currency' => 'USD']);
        $project->forceFill(['status' => 'active', 'created_by' => ($by ?? test()->finance)->id])->save();

        return $project;
    });
}

function paidExpense(Project $project, string $description, User $by): void
{
    test()->asTenant('acme', fn () => Spending::record($project, ['category' => CostCategory::Travel, 'description' => $description, 'supplier' => 'Air Nile', 'amount_minor' => 12_550, 'grant_id' => null], CarbonImmutable::parse('2026-11-03'), $by));
}

it('exports a project\'s spending as CSV, only with finance access', function () {
    $project = exportProject();
    paidExpense($project, 'Flights to Juba', $this->finance);
    $url = $this->organizationUrl('acme', "projects/{$project->id}/expenses/export");

    $this->actingAs($this->plain)->get($url)->assertForbidden();

    $csv = $this->actingAs($this->finance)->get($url)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toContain('Flights to Juba')->toContain('Air Nile')->toContain('125.50')->toContain('2026-11-03');
});

it('keeps text a spreadsheet would run as a formula as text', function () {
    $project = exportProject();
    paidExpense($project, '=HYPERLINK("http://evil.example")', $this->finance);

    $csv = $this->actingAs($this->finance)->get($this->organizationUrl('acme', "projects/{$project->id}/expenses/export"))->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',"=HYPERLINK');
});

it('does not export another organization\'s project', function () {
    $theirs = exportProject('other', 'THEIR', $this->member('other', ['projects.view']));

    $this->actingAs($this->finance)->get($this->organizationUrl('acme', "projects/{$theirs->id}/expenses/export"))->assertNotFound();
});

it('exports the grants, only with grants access', function () {
    $this->asTenant('acme', function () {
        $funder = Funder::query()->create(['name' => 'Nile Fund', 'kind' => 'foundation']);
        $grant = new Grant(['funder_id' => $funder->id, 'reference' => 'NF/1', 'title' => 'Water', 'currency' => 'EUR', 'amount_minor' => 100_000]);
        $grant->forceFill(['status' => GrantStatus::Active, 'created_by' => $this->finance->id])->save();
    });

    $this->actingAs($this->plain)->get($this->organizationUrl('acme', 'grants/export'))->assertForbidden();
    expect($this->actingAs($this->finance)->get($this->organizationUrl('acme', 'grants/export'))->assertOk()->streamedContent())
        ->toContain('NF/1')->toContain('Nile Fund')->toContain('EUR')->toContain('1000.00');
});

it('searches what the member may open', function () {
    $project = exportProject();
    paidExpense($project, 'Water pump for Juba', $this->finance);

    $groups = fn (User $user) => $this->actingAs($user)->get($this->organizationUrl('acme', 'search?q=WATER'))->assertOk();

    $groups($this->finance)->assertInertia(fn (Assert $page) => $page->component('portal/search', false)
        ->where('groups.0.title', 'Projects')->where('groups.0.results.0.label', 'WASH · WASH water project')
        ->where('groups.1.title', 'Spending')->where('groups.1.results.0.label', 'Water pump for Juba'));

    $groups($this->plain)->assertInertia(fn (Assert $page) => $page->component('portal/search', false)
        ->has('groups', 1)->where('groups.0.title', 'Projects'));
});

it('finds nothing in another organization', function () {
    exportProject('other', 'THEIR', $this->member('other', ['projects.view']));

    $this->actingAs($this->finance)->get($this->organizationUrl('acme', 'search?q=THEIR'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('portal/search', false)->has('groups', 0));
});

it('treats % and _ as text, and waits for two characters', function () {
    exportProject();

    foreach (['%%', '__', 'w'] as $term) {
        $this->actingAs($this->finance)->get($this->organizationUrl('acme', 'search?q='.urlencode($term)))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/search', false)->has('groups', 0));
    }
});
