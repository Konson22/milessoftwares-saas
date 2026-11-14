<?php

use App\Models\Indicator;
use App\Models\Programme;
use App\Models\ProgrammeUnit;
use App\Models\ReportingPeriod;
use App\Models\Result;
use App\Models\Submission;
use App\Models\User;
use App\Platform\SetUpWorkspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Miles\PlatformClient\Api\Organization;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A role's permissions as the catalog defines them.
 *
 * @return list<string>
 */
function rolePermissions(string $role): array
{
    static $catalog = null;
    $catalog ??= json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);

    foreach ($catalog['roles'] as $definition) {
        if ($definition['key'] === $role) {
            return $definition['permissions'];
        }
    }

    throw new InvalidArgumentException("No role {$role} in the catalog.");
}

/**
 * Signs in as someone holding a catalog role (a new person unless given).
 */
function actingAsRole(string $role, ?Authenticatable $user = null, string $slug = 'acme', string $name = 'Test Member'): User
{
    $user ??= test()->platformUser(['name' => $name]);
    test()->actingAsMember($slug, rolePermissions($role), user: $user);

    // Pickers list people who have opened M&E: open it once.
    test()->get(test()->workspaceUrl($slug))->assertOk();

    assert($user instanceof User);

    return $user;
}

function provision(string $slug = 'acme'): void
{
    $organization = Organization::fromArray(test()->platform()->organization($slug));
    test()->asTenant($slug, fn () => app(SetUpWorkspace::class)->provision($organization));
}

function mel(string $path, string $slug = 'acme'): string
{
    return test()->workspaceUrl($slug, $path);
}

/**
 * Within the tenant.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function inAcme(callable $callback, string $slug = 'acme'): mixed
{
    return test()->asTenant($slug, $callback);
}

/**
 * A programme for 2026 with two units, quarterly periods (due 15 days
 * after), a goal, an outcome and an output, set up through the pages by
 * whoever is signed in (an M&E manager).
 *
 * @return array{programme: Programme, units: array<string, ProgrammeUnit>, periods: array<string, ReportingPeriod>, output: Result}
 */
function setUpProgramme(string $code = 'WASH', string $slug = 'acme'): array
{
    test()->post(mel('programmes', $slug), [
        'code' => $code, 'name' => 'Clean water', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $programme = inAcme(fn () => Programme::query()->where('code', $code)->sole(), $slug);

    foreach (['JUB' => 'Juba', 'BOR' => 'Bor'] as $unitCode => $name) {
        test()->post(mel("programmes/{$programme->id}/units", $slug), ['code' => $unitCode, 'name' => $name, 'kind' => 'site'])->assertSessionHasNoErrors();
    }
    test()->post(mel("programmes/{$programme->id}/periods/generate", $slug), ['frequency' => 'quarterly', 'due_after_days' => 15])->assertSessionHasNoErrors();

    $levels = inAcme(fn () => $programme->levels()->get()->keyBy('depth'), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[1]->id, 'code' => '1', 'statement' => 'Healthier communities'])->assertSessionHasNoErrors();
    $goal = inAcme(fn () => Result::query()->where('code', '1')->where('programme_id', $programme->id)->sole(), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[2]->id, 'parent_id' => $goal->id, 'code' => '1.1', 'statement' => 'Households use safe water'])->assertSessionHasNoErrors();
    $outcome = inAcme(fn () => Result::query()->where('code', '1.1')->where('programme_id', $programme->id)->sole(), $slug);
    test()->post(mel("programmes/{$programme->id}/results", $slug), ['level_id' => $levels[3]->id, 'parent_id' => $outcome->id, 'code' => '1.1.1', 'statement' => 'Water points built'])->assertSessionHasNoErrors();

    return inAcme(fn () => [
        'programme' => $programme,
        'units' => $programme->units()->get()->keyBy('code')->all(),
        'periods' => $programme->periods()->get()->keyBy('label')->all(),
        'output' => Result::query()->where('code', '1.1.1')->where('programme_id', $programme->id)->sole(),
    ], $slug);
}

/**
 * An active quarterly indicator under a result.
 *
 * @param  array<string, mixed>  $definition
 */
function addIndicator(Programme $programme, Result $result, string $code, array $definition = [], string $slug = 'acme'): Indicator
{
    test()->post(mel("programmes/{$programme->id}/indicators", $slug), [
        'result_id' => $result->id,
        'code' => $code,
        'frequency' => 'quarterly',
        'name' => 'People with access to safe water',
        'type' => 'output',
        'measure' => 'count',
        'unit_label' => 'people',
        'decimals' => 0,
        'direction' => 'increase',
        'time_aggregation' => 'sum',
        'unit_aggregation' => 'sum',
        ...$definition,
    ])->assertSessionHasNoErrors();

    $indicator = inAcme(fn () => Indicator::query()->where('programme_id', $programme->id)->where('code', strtoupper($code))->sole(), $slug);
    test()->put(mel("programmes/{$programme->id}/indicators/{$indicator->id}/status", $slug), ['status' => 'active'])->assertSessionHasNoErrors();

    return $indicator;
}

/**
 * Saves (and by default submits) a value through the entry form.
 *
 * @param  array<string, mixed>  $figure
 */
function enterResult(Programme $programme, ReportingPeriod $period, Indicator $indicator, ProgrammeUnit $unit, array $figure, bool $submit = true, string $slug = 'acme'): Submission
{
    test()->post(mel("programmes/{$programme->id}/periods/{$period->id}/indicators/{$indicator->id}/units/{$unit->id}", $slug), [...$figure, 'submit' => $submit])
        ->assertSessionHasNoErrors();

    return inAcme(fn () => Submission::query()
        ->where('indicator_id', $indicator->id)->where('reporting_period_id', $period->id)->where('unit_id', $unit->id)
        ->orderByDesc('revision')->firstOrFail(), $slug);
}
