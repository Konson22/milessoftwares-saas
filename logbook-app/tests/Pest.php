<?php

use App\Logbook\Access;
use App\Logbook\Logbooks;
use App\Logbook\Member;
use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
 * Signs in as someone holding a catalog role (a new person unless given),
 * and opens Logbook once so they appear in people pickers.
 */
function actingAsRole(string $role, ?Authenticatable $user = null, string $slug = 'acme', string $name = 'Test Member'): User
{
    $user ??= test()->platformUser(['name' => $name]);
    test()->actingAsMember($slug, rolePermissions($role), user: $user);
    Cache::flush();
    test()->get(test()->workspaceUrl($slug))->assertOk();

    assert($user instanceof User);

    return $user;
}

/**
 * Switches to someone already known. Access is cached per member, so the
 * cache is dropped as the platform's webhook would.
 */
function as_(User $user, string $role, string $slug = 'acme'): void
{
    test()->actingAsMember($slug, rolePermissions($role), user: $user);
    Cache::flush();
}

function p(string $path = '', string $slug = 'acme'): string
{
    return test()->workspaceUrl($slug, $path);
}

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function inTenant(callable $callback, string $slug = 'acme'): mixed
{
    return test()->asTenant($slug, $callback);
}

/**
 * The cast of an organization: a manager, two contributors, a reviewer, a
 * viewer and an auditor, each having opened Logbook.
 *
 * @return array<string, User>
 */
function team(string $slug = 'acme'): array
{
    $people = [];
    foreach ([
        'manager' => 'logbook_manager',
        'writer' => 'logbook_contributor',
        'other' => 'logbook_contributor',
        'reviewer' => 'logbook_reviewer',
        'viewer' => 'logbook_viewer',
        'auditor' => 'logbook_auditor',
    ] as $key => $role) {
        $people[$key] = actingAsRole($role, slug: $slug, name: ucfirst($key));
    }

    return $people;
}

/**
 * A logbook made by whoever is signed in (a manager).
 *
 * @param  array<string, mixed>  $extra
 */
function logbook(string $name = 'Facility Management', array $extra = [], string $slug = 'acme'): Logbook
{
    test()->post(p('logbooks', $slug), ['name' => $name, ...$extra])->assertSessionHasNoErrors();
    $logbook = inTenant(fn () => Logbook::query()->where('name', $name)->latest()->firstOrFail(), $slug);

    // Everyone signed up so far becomes a member in the first role theirs allows,
    // as on the day membership became required; restricted ones start empty.
    if (! ($extra['restricted'] ?? false)) {
        inTenant(function () use ($logbook) {
            foreach (User::query()->get() as $user) {
                $member = Member::of((string) $user->id);
                if ($member === null || Access::managesLogbook($logbook, $member)) {
                    continue;
                }
                foreach (Logbooks::MEMBER_PERMISSIONS as $role => [$permission]) {
                    if ($member->can($permission)) {
                        AccessGrant::query()->create(['logbook_id' => $logbook->id, 'user_id' => $user->id, 'role' => $role, 'granted_by' => $logbook->created_by]);
                        break;
                    }
                }
            }
        }, $slug);
    }

    return $logbook;
}

/** Makes someone a member of a logbook directly (a person who joined after it was made). */
function addMember(Logbook $logbook, User $user, string $role = 'contributor', string $slug = 'acme'): void
{
    inTenant(fn () => AccessGrant::query()->create(['logbook_id' => $logbook->id, 'user_id' => $user->id, 'role' => $role, 'granted_by' => $logbook->created_by]), $slug);
}

/** Takes someone off a logbook: they no longer reach it. */
function removeMember(Logbook $logbook, User $user, string $slug = 'acme'): void
{
    inTenant(fn () => AccessGrant::query()->where('logbook_id', $logbook->id)->where('user_id', $user->id)->delete(), $slug);
}

/**
 * The generator sheet's fields: numbers, a choice, a person, text and a file.
 *
 * @return list<array<string, mixed>>
 */
function generatorFields(): array
{
    return [
        ['name' => 'generator', 'label' => 'Generator', 'type' => 'text', 'required' => true, 'max_length' => 20],
        ['name' => 'hours', 'label' => 'Running hours', 'type' => 'decimal', 'required' => true, 'min' => '0', 'max' => '24'],
        ['name' => 'fuel', 'label' => 'Fuel (litres)', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'starts', 'label' => 'Starts', 'type' => 'integer', 'required' => false],
        ['name' => 'condition', 'label' => 'Condition', 'type' => 'select_one', 'required' => true, 'choices' => [['value' => 'good', 'label' => 'Good'], ['value' => 'faulty', 'label' => 'Faulty']]],
        ['name' => 'checks', 'label' => 'Checks', 'type' => 'select_multiple', 'required' => false, 'choices' => [['value' => 'oil', 'label' => 'Oil'], ['value' => 'coolant', 'label' => 'Coolant']]],
        ['name' => 'serviced', 'label' => 'Serviced', 'type' => 'yes_no', 'required' => false],
        ['name' => 'run_date', 'label' => 'Date', 'type' => 'date', 'required' => false],
        ['name' => 'started_at', 'label' => 'Start', 'type' => 'time', 'required' => false],
        ['name' => 'logged_at', 'label' => 'Logged', 'type' => 'datetime', 'required' => false],
        ['name' => 'operator', 'label' => 'Operator', 'type' => 'user', 'required' => false],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'file', 'required' => false],
    ];
}

/**
 * A sheet in the logbook, its fields saved and published, as a manager.
 *
 * @param  list<array<string, mixed>>|null  $fields
 */
function publishedSheet(Logbook $logbook, string $code = 'GEN', bool $review = true, ?array $fields = null, string $slug = 'acme'): LogSheet
{
    test()->post(p("logbooks/{$logbook->id}/sheets", $slug), ['name' => "{$code} log", 'code' => $code, 'review_required' => $review])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->firstOrFail(), $slug);
    test()->post(p("sheets/{$sheet->id}/publish", $slug), ['fields' => $fields ?? generatorFields()])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh(), $slug);
}

/**
 * Valid generator values.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generatorValues(array $overrides = []): array
{
    return ['generator' => 'GEN-01', 'hours' => '6.5', 'fuel' => '40', 'condition' => 'good', ...$overrides];
}

/**
 * Writes an entry as whoever is signed in.
 *
 * @param  array<string, mixed>  $values
 */
function writeEntry(LogSheet $sheet, array $values = [], bool $submit = true, ?string $key = null, string $slug = 'acme'): TestResponse
{
    return test()->post(p('entries', $slug), [
        'sheet_id' => $sheet->id,
        'occurred_at' => '2026-11-19T08:30',
        'values' => $values === [] ? generatorValues() : $values,
        'idempotency_key' => $key ?? (string) Str::uuid7(),
        'submit' => $submit,
    ]);
}

function latestEntry(string $slug = 'acme'): Entry
{
    return inTenant(fn () => Entry::query()->latest('created_at')->latest('id')->firstOrFail(), $slug);
}
