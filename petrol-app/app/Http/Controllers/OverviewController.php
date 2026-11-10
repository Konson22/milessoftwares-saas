<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeesAcrossBranches;
use App\Models\ExchangeRate;
use App\Models\Station;
use App\Models\User;
use App\Petrol\Currencies;
use App\Petrol\Executive;
use App\Petrol\Fx;
use App\Petrol\MainBranch;
use App\Petrol\Permissions;
use App\Support\Branches;
use App\Support\BusinessDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * Company overview across the branches this member may report on.
 * A branch id from the query is used only after it is checked against that list.
 */
class OverviewController extends Controller
{
    use SeesAcrossBranches;

    public function index(Request $request, Branches $branches, Executive $executive): Response
    {
        $allowed = $this->branchesWith($branches, [Permissions::REPORTS_VIEW, Permissions::CASH_REPORT]);
        $selected = $request->string('branch')->toString();
        $ids = array_map(fn (Branch $branch) => $branch->id, $allowed);
        $picked = array_values(array_filter((array) $request->input('branches', []), 'is_string'));

        if ($picked !== []) {
            foreach ($picked as $id) {
                abort_unless(in_array($id, $ids, true), 404);
            }
            $ids = $picked;
        } elseif ($selected !== '' && $selected !== 'all') {
            abort_unless(in_array($selected, $ids, true), 404);
            $ids = [$selected];
        }

        $today = BusinessDate::today();
        $from = $this->date($request->string('from')->toString(), $today);
        $to = $this->date($request->string('to')->toString(), $today);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            $from = CarbonImmutable::parse($to)->subDays(366)->toDateString();
        }

        $names = [];
        foreach ($allowed as $branch) {
            $names[$branch->id] = $branch->name;
        }

        $mains = Station::query()->whereIn('branch_id', array_map(fn (Branch $branch) => $branch->id, $allowed))->where('is_main', true)->pluck('branch_id')->flip();

        return Inertia::render('overview/index', [
            'from' => $from,
            'to' => $to,
            'branchId' => count($ids) === 1 && count($allowed) > 1 ? $ids[0] : null,
            'canDesignate' => $this->canDesignate(),
            'presets' => [
                ['label' => 'Today', 'from' => $today, 'to' => $today],
                ['label' => '7 days', 'from' => CarbonImmutable::parse($today)->subDays(6)->toDateString(), 'to' => $today],
                ['label' => '30 days', 'from' => CarbonImmutable::parse($today)->subDays(29)->toDateString(), 'to' => $today],
            ],
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'main' => isset($mains[$branch->id]),
            ], $allowed),
            'currency' => ($shown = $this->shownCurrency($request->string('currency')->toString()))['currency'],
            'currencyNote' => $shown['note'],
            'generatedAt' => now()->toIso8601String(),
            'reportingCurrencies' => app(Currencies::class)->codes(),
            'overview' => $this->withCustodyEquivalent($shown['apply']($executive->overview($ids, $names, $from, $to)), $shown['currency']),
        ]);
    }

    public function designate(Request $request, Branches $branches, MainBranch $main): RedirectResponse
    {
        abort_unless($this->canDesignate(), 403);

        $validated = $request->validate(['branch_id' => ['required', 'uuid']]);
        $branch = $branches->find($validated['branch_id']);
        abort_if($branch === null, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $main->designate($branch->id, $user);

        return back()->with('success', "{$branch->name} is now the main branch.");
    }

    private function canDesignate(): bool
    {
        $access = app(CurrentAccess::class)->get();

        return $access !== null
            && $access->allowsIn(Permissions::STATIONS_MANAGE)
            && $access->branchesFor(Permissions::STATIONS_MANAGE) === null;
    }

    /**
     * @return array{currency: string, note: string, apply: callable(array<string, mixed>): array<string, mixed>}
     */
    private function shownCurrency(string $requested): array
    {
        $books = Money::currency();
        $requested = strtoupper($requested);
        $same = [
            'currency' => $books,
            'note' => 'Book figures are in '.$books.'. Each transaction keeps the rate it used. Litres are not converted.',
            'apply' => fn (array $overview): array => $overview,
        ];
        if ($requested === '' || $requested === $books || ! in_array($requested, app(Currencies::class)->codes(), true)) {
            return $same;
        }

        $main = Station::query()->where('is_main', true)->value('branch_id');
        $rate = is_string($main) ? ExchangeRate::open($main, $books, $requested) : null;
        if ($rate === null) {
            return [
                'currency' => $books,
                'note' => 'No current '.$requested.' rate is set at the main branch, so figures stay in '.$books.'.',
                'apply' => fn (array $overview): array => $overview,
            ];
        }

        return [
            'currency' => $requested,
            'note' => 'Shown in '.$requested.' at the main branch’s current rate. The books stay in '.$books.'. Litres are not converted.',
            'apply' => fn (array $overview): array => $this->convertMoney($overview, $books, $requested, $rate),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function convertMoney(array $data, string $from, string $to, ExchangeRate $rate): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->convertMoney($value, $from, $to, $rate);
            } elseif (is_string($key) && str_ends_with($key, 'Minor') && is_int($value)) {
                $data[$key] = Fx::convert($value, $from, $to, $rate);
            }
        }

        return $data;
    }

    /**
     * Fold physical currency piles into one labelled display total.
     * The piles themselves are left in the currency they were counted in.
     *
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function withCustodyEquivalent(array $overview, string $target): array
    {
        $lines = $overview['operations']['custodyCurrencies'] ?? [];
        if (! is_array($lines)) {
            return $overview;
        }

        $main = Station::query()->where('is_main', true)->value('branch_id');
        $rate = is_string($main) ? ExchangeRate::open($main, Money::currency(), $target === Money::currency() ? 'USD' : $target) : null;
        $fields = ['attendant', 'cashier', 'safe', 'inTransit', 'confirmed', 'depositGap', 'pending', 'remitted', 'shortage', 'overage'];
        $totals = [];
        $complete = true;
        foreach ($fields as $field) {
            $totals[$field] = 0;
        }
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $from = strtoupper((string) ($line['currency'] ?? ''));
            foreach ($fields as $field) {
                $amount = (int) ($line[$field] ?? 0);
                if ($from === $target || $amount === 0) {
                    $totals[$field] += $amount;

                    continue;
                }
                if ($rate === null) {
                    $complete = false;

                    continue;
                }
                try {
                    $totals[$field] += Fx::convert($amount, $from, $target, $rate);
                } catch (\InvalidArgumentException) {
                    $complete = false;
                }
            }
        }

        $label = 'Physical balances above stay in the currency that was counted. ';
        $label .= $rate === null
            ? 'A reporting total needs a current rate at the main branch.'
            : 'The reporting total uses the main branch rate of '.Money::toMajor((int) $rate->rate_minor, $rate->quote_currency).' '.$rate->quote_currency.' for 1 '.$rate->base_currency.', current since '.$rate->effective_from->toDateTimeString().'. It is a display figure, not a physical exchange, and it does not rewrite recorded cash. A shortage in one currency is not netted against an overage in another.';

        $overview['operations']['custodyEquivalent'] = [
            'currency' => $target,
            'complete' => $complete,
            'label' => $label,
            ...$totals,
        ];

        return $overview;
    }

    private function date(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
