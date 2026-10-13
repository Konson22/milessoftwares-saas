<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Miles\PlatformClient\Tenancy\CurrentTenant;

abstract class Controller
{
    protected function organizationId(): string
    {
        return (string) app(CurrentTenant::class)->id();
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * An existing row of this organization (validation rule).
     */
    protected function ours(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->organizationId());
    }

    /**
     * An open account of this organization, optionally only banks or only non-banks.
     */
    protected function openAccount(?bool $bank = null): Exists
    {
        $rule = $this->ours('accounts')->where('active', true);

        return match ($bank) {
            true => $rule->whereNotNull('bank_kind'),
            false => $rule->whereNull('bank_kind'),
            null => $rule,
        };
    }

    /**
     * Open accounts for a select: [{id, label, type}].
     *
     * @return list<array{id: string, label: string, type: string}>
     */
    protected function accountOptions(?bool $bank = null): array
    {
        return array_values(Account::query()
            ->where('active', true)
            ->when($bank === true, fn ($query) => $query->whereNotNull('bank_kind'))
            ->when($bank === false, fn ($query) => $query->whereNull('bank_kind'))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label(), 'type' => $account->type->value])
            ->all());
    }

    /**
     * A from/to period from the query string (validated), with defaults.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function period(Request $request, CarbonImmutable $defaultFrom): array
    {
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            isset($period['from']) ? CarbonImmutable::parse($period['from']) : $defaultFrom,
            isset($period['to']) ? CarbonImmutable::parse($period['to']) : CarbonImmutable::today(),
        ];
    }
}
