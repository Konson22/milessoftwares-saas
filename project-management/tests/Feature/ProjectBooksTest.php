<?php

use App\Actions\Grants;
use App\Actions\ProjectBooks;
use App\Actions\Spending;
use App\Enums\CostCategory;
use App\Enums\GrantStatus;
use App\Models\Expense;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| The books are Finance's. Project money is sent there (the platform outbox)
| in the same transaction: paying is a finance.project_expense.paid event on
| the category and the project, voiding takes it back, a grant payment
| received is finance.grant.received. Amounts go in their own currency.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->requester = $this->member('acme', ['projects.view']);
    $this->payer = $this->member('acme', ['projects.view']);
});

function booksProject(string $currency = 'USD'): Project
{
    return test()->asTenant('acme', function () use ($currency) {
        $project = new Project(['code' => 'WASH', 'name' => 'WASH Juba', 'currency' => $currency]);
        $project->forceFill(['status' => 'active', 'budget_minor' => 0, 'created_by' => test()->payer->id])->save();

        return $project;
    });
}

function directExpense(Project $project, User $by, int $minor = 12_500, CostCategory $category = CostCategory::Travel): Expense
{
    return Spending::record($project, ['category' => $category, 'description' => 'Flights to Juba', 'supplier' => 'Air Nile', 'amount_minor' => $minor, 'grant_id' => null], CarbonImmutable::parse('2026-11-03'), $by);
}

function booksGrant(string $currency, User $by): Grant
{
    $funder = Funder::query()->create(['name' => 'Nile Fund', 'kind' => 'foundation']);
    $grant = Grants::create(['funder_id' => $funder->id, 'reference' => 'NF/1', 'title' => 'Water', 'currency' => $currency, 'amount_minor' => 1_000_000, 'restricted' => false], GrantStatus::Opportunity, $by);
    Grants::award($grant, 1_000_000, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-12-31'), $by);

    return $grant->refresh();
}

it('sends a paid expense to Finance, on its category and project', function () {
    $expense = $this->asTenant('acme', fn () => directExpense(booksProject(), $this->payer));

    $events = $this->financeEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('finance.project_expense.paid')
        ->and($events[0]['transaction'])->toBe("expense:{$expense->id}:paid")
        ->and($events[0]['payload'])->toBe([
            'reference' => "expense:{$expense->id}",
            'paid_on' => '2026-11-03',
            'currency' => 'USD',
            'amount_minor' => 12_500,
            'description' => 'WASH: Flights to Juba',
            'category' => 'travel',
            'method' => ProjectBooks::BANK_TRANSFER,
            'supplier' => 'Air Nile',
            'payment_reference' => null,
            'project' => ['code' => 'WASH', 'name' => 'WASH Juba'],
        ]);

    // Queued for delivery, as the organization's own event.
    $row = DB::table('platform_outbox')->first();
    expect($row->organization_id)->toBe($this->organization('acme')->id)
        ->and($row->status)->toBe('pending')
        ->and($row->schema_version)->toBe(1);
});

it('sends nothing while spending is only requested', function () {
    $this->asTenant('acme', fn () => Spending::request(booksProject(), ['category' => CostCategory::Travel, 'description' => 'Flights', 'supplier' => null, 'amount_minor' => 100, 'grant_id' => null], $this->requester));

    expect($this->financeEvents())->toBe([]);
});

it('takes the payment back when it is voided', function () {
    $expense = $this->asTenant('acme', function () {
        $expense = directExpense(booksProject(), $this->payer);
        Spending::cancel($expense, 'Paid twice', $this->payer);

        return $expense;
    });

    $voided = $this->financeEvents('acme', 'finance.project_expense.voided');
    expect($voided)->toHaveCount(1)
        ->and($voided[0]['payload'])->toBe(['reference' => "expense:{$expense->id}", 'voided_on' => '2026-11-04', 'reason' => 'Paid twice']);
});

it('sends a cancelled commitment nowhere', function () {
    $this->asTenant('acme', function () {
        $expense = Spending::request(booksProject(), ['category' => CostCategory::Travel, 'description' => 'Flights', 'supplier' => null, 'amount_minor' => 100, 'grant_id' => null], $this->requester);
        Spending::cancel($expense, null, $this->requester);
    });

    expect($this->financeEvents())->toBe([]);
});

it('sends a received grant payment once, in the grant\'s currency', function () {
    $payment = $this->asTenant('acme', function () {
        $grant = booksGrant('EUR', $this->payer);
        $payment = Grants::schedule($grant, CarbonImmutable::parse('2026-11-01'), 500_000, 'First', $this->payer);
        Grants::receive($payment, CarbonImmutable::parse('2026-11-02'), $this->payer);

        expect(fn () => Grants::receive($payment->refresh(), CarbonImmutable::parse('2026-11-02'), $this->payer))->toThrow(ValidationException::class);

        return $payment;
    });

    $events = $this->financeEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('finance.grant.received')
        ->and($events[0]['payload'])->toBe([
            'reference' => "grant-payment:{$payment->id}",
            'grant_reference' => 'NF/1',
            'grant_title' => 'Water',
            'funder' => 'Nile Fund',
            'received_on' => '2026-11-02',
            'currency' => 'EUR',
            'amount_minor' => 500_000,
            'method' => ProjectBooks::BANK_TRANSFER,
        ]);
});

it('records the event only when the payment is kept', function () {
    $this->asTenant('acme', function () {
        $project = booksProject();
        try {
            DB::transaction(function () use ($project) {
                directExpense($project, $this->payer);

                throw new RuntimeException('Rolled back');
            });
        } catch (RuntimeException) {
        }

        expect(Expense::query()->count())->toBe(0);
    });

    expect($this->financeEvents())->toBe([]);
});

it('keeps each organization\'s events apart', function () {
    $this->asTenant('acme', fn () => directExpense(booksProject(), $this->payer));

    expect($this->financeEvents('acme'))->toHaveCount(1)
        ->and($this->financeEvents('other'))->toBe([]);
});
