<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Money\EnteredAmountRecord;
use Miles\PlatformClient\Money\EntryCurrencies;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Miles\PlatformClient\Tests\Fixtures\Prescription;

beforeEach(function () {
    if (! Schema::hasTable('workspaces')) {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->char('currency', 3);
        });
    }

    $this->org = '11111111-1111-4111-8111-111111111111';
    DB::table('workspaces')->insert(['id' => '22222222-2222-4222-8222-222222222222', 'organization_id' => $this->org, 'currency' => 'SSP']);
    app(CurrentTenant::class)->set(new Organization($this->org, 'acme', 'Acme', 'active', 'SS', 'SSP', 'Africa/Juba'));
    $this->money = app(EntryCurrencies::class);
});

it('takes the workspace currency as typed', function () {
    $amount = $this->money->enter('1,250.5', null, 'amount');

    expect($amount->foreign())->toBeFalse()
        ->and($amount->baseMinor)->toBe(125050)
        ->and($amount->currency)->toBe('SSP');
});

it('converts another currency at its rate', function () {
    $this->money->set('usd', '7800');
    $amount = $this->money->enter('2.50', 'USD', 'amount');

    expect($amount->foreign())->toBeTrue()
        ->and($amount->amountMinor)->toBe(250)
        ->and($amount->rateMinor)->toBe(780000)
        ->and($amount->baseMinor)->toBe(1950000);
});

it('refuses a currency without a rate', function () {
    $this->money->enter('10', 'EUR', 'unit_cost');
})->throws(ValidationException::class);

it('refuses decimals a currency does not have', function () {
    $this->money->set('UGX', '0.25');
    $this->money->enter('100.5', 'UGX', 'amount');
})->throws(ValidationException::class);

it('converts a currency without decimals', function () {
    $this->money->set('UGX', '0.25');

    expect($this->money->enter('1000', 'UGX', 'amount')->baseMinor)->toBe(25000);
});

it('will not set a rate for the workspace currency', function () {
    $this->money->set('SSP', '1');
})->throws(ValidationException::class);

it('lists the workspace currency first', function () {
    $this->money->set('USD', '7800');

    $page = $this->money->forPage();

    expect($page['base'])->toBe('SSP')
        ->and(array_column($page['currencies'], 'code'))->toBe(['SSP', 'USD'])
        ->and(array_column($page['currencies'], 'rate'))->toBe([null, '7800.00']);
});

it('remembers only amounts typed in another currency', function () {
    $this->money->set('USD', '7800');
    $subject = new Prescription;
    $subject->forceFill(['id' => 'rx-1']);

    $this->money->remember($subject, 'amount', $this->money->enter('5', null, 'amount'));
    $this->money->remember($subject, 'amount_tendered', $this->money->enter('2', 'USD', 'amount_tendered'));

    expect(EnteredAmountRecord::query()->count())->toBe(1)
        ->and($this->money->enteredOn($subject))->toBe([
            'amount_tendered' => ['currency' => 'USD', 'amount' => '2.00', 'rate' => '7800.00', 'base' => '15600.00'],
        ]);
});
