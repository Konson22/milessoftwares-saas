<?php

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\Award;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\Supplier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
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
 * and opens Procurement once so they appear in people pickers.
 */
function actingAsRole(string $role, ?Authenticatable $user = null, string $slug = 'acme', string $name = 'Test Member'): User
{
    $user ??= test()->platformUser(['name' => $name]);
    test()->actingAsMember($slug, rolePermissions($role), user: $user);
    test()->get(test()->workspaceUrl($slug))->assertOk();

    assert($user instanceof User);

    return $user;
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
 * @param  array<string, mixed>  $values
 */
function settings(array $values, string $slug = 'acme'): void
{
    inTenant(fn () => Workspace::settings()->forceFill($values)->save(), $slug);
}

/**
 * The cast of an organization: a requester, a department approver, a
 * procurement officer, a manager, an evaluator and a receiving officer.
 *
 * @return array<string, User>
 */
function team(string $slug = 'acme'): array
{
    $people = [];
    foreach ([
        'requester' => 'procurement_requester',
        'approver' => 'procurement_department_approver',
        'officer' => 'procurement_officer',
        'manager' => 'procurement_manager',
        'evaluator' => 'procurement_evaluator',
        'receiver' => 'procurement_receiving_officer',
    ] as $key => $role) {
        $people[$key] = actingAsRole($role, slug: $slug, name: ucfirst($key));
    }

    return $people;
}

/**
 * Switches to someone already in the cast. Access is cached per member
 * (as the platform client does for max_age), so changing what the same
 * person holds drops the cache, as the platform's webhook would.
 *
 * @param  list<string>  $extra
 */
function as_(User $user, string $role, string $slug = 'acme', array $extra = []): void
{
    test()->actingAsMember($slug, [...rolePermissions($role), ...$extra], user: $user);
    Cache::flush();
}

function supplier(string $name, string $slug = 'acme', array $extra = []): Supplier
{
    test()->post(p('suppliers', $slug), ['name' => $name, 'type' => 'company', ...$extra])->assertSessionHasNoErrors();

    return inTenant(fn () => Supplier::query()->where('name', $name)->sole(), $slug);
}

/**
 * A requisition saved (and by default submitted) by whoever is signed in.
 *
 * @param  array<string, mixed>  $overrides
 */
function requisition(array $overrides = [], bool $submit = true, string $slug = 'acme'): Requisition
{
    $title = $overrides['title'] ?? 'Laptops for the finance team';
    test()->post(p('requisitions', $slug), [
        'title' => $title,
        'justification' => 'The old ones no longer run the accounting software.',
        'currency' => 'SSP',
        'needed_by' => now()->addMonth()->toDateString(),
        'lines' => [
            ['kind' => 'goods', 'description' => 'Laptop, 16 GB', 'quantity' => '4', 'unit' => 'pcs', 'unit_price' => '1000'],
            ['kind' => 'service', 'description' => 'Set-up and data transfer', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '400'],
        ],
        'submit' => $submit,
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Requisition::query()->where('title', $title)->latest()->firstOrFail(), $slug);
}

function decide(string $path, string $decision = 'approve', ?string $note = null, string $slug = 'acme'): TestResponse
{
    return test()->post(p($path, $slug), ['decision' => $decision, 'note' => $note]);
}

/**
 * An issued RFQ for the requisition with these suppliers invited and a
 * mandatory pass/fail criterion plus a scored one. Signed in as an officer.
 *
 * @param  list<Supplier>  $suppliers
 */
function issuedRfq(Requisition $requisition, array $suppliers, string $method = 'rfq', string $slug = 'acme'): SourcingEvent
{
    test()->post(p('sourcing', $slug), [
        'requisition_id' => $requisition->id,
        'method' => $method,
        'quotes_due_at' => now()->addWeek()->toDateTimeString(),
        'method_reason' => $method === 'single_source' ? 'Only authorized dealer.' : null,
    ])->assertSessionHasNoErrors();
    $event = inTenant(fn () => SourcingEvent::query()->where('requisition_id', $requisition->id)->latest()->firstOrFail(), $slug);

    test()->post(p("sourcing/{$event->id}/invitations", $slug), ['supplier_ids' => array_map(fn ($s) => $s->id, $suppliers)])->assertSessionHasNoErrors();
    test()->put(p("sourcing/{$event->id}/criteria", $slug), ['criteria' => [
        ['name' => 'Meets the specification', 'kind' => 'pass_fail', 'mandatory' => true],
        ['name' => 'After-sales support', 'kind' => 'score', 'max_score' => 10],
    ]])->assertSessionHasNoErrors();
    test()->post(p("sourcing/{$event->id}/issue", $slug))->assertSessionHasNoErrors();

    return inTenant(fn () => $event->fresh(), $slug);
}

/**
 * Records a quotation pricing every item at the given unit prices (by line number).
 *
 * @param  array<int, string>  $prices
 */
function quote(SourcingEvent $event, Supplier $supplier, array $prices, string $currency = 'SSP', array $extra = [], string $slug = 'acme'): Quotation
{
    $items = inTenant(fn () => $event->items()->get(), $slug);
    test()->post(p("sourcing/{$event->id}/quotations", $slug), [
        'supplier_id' => $supplier->id,
        'quoted_on' => now()->toDateString(),
        'currency' => $currency,
        'lines' => $items->map(fn ($item) => ['sourcing_item_id' => $item->id, 'unit_price' => $prices[$item->line_no], 'compliant' => true])->all(),
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Quotation::query()->where('sourcing_event_id', $event->id)->where('supplier_id', $supplier->id)->sole(), $slug);
}

function award(SourcingEvent $event, string $slug = 'acme'): Award
{
    return inTenant(fn () => Award::query()->where('sourcing_event_id', $event->id)->latest()->firstOrFail(), $slug);
}

function order(string $slug = 'acme'): PurchaseOrder
{
    return inTenant(fn () => PurchaseOrder::query()->latest()->firstOrFail(), $slug);
}
