<?php

namespace App\Http\Controllers;

use App\Finance\Currencies;
use App\Finance\Money;
use App\Finance\Revaluations;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Revaluation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Other currencies: the rates typed by the organization, and month-end
 * revaluations of foreign-currency balances (plan feature "multicurrency").
 */
class CurrencyController extends Controller
{
    public function index(Request $request, Revaluations $revaluations): Response
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? null;
        $asOf = $asOf === null ? CarbonImmutable::today()->subMonthNoOverflow()->endOfMonth()->startOfDay() : CarbonImmutable::parse($asOf);

        // A preview needs every rate: say which is missing instead of failing the page.
        try {
            $preview = $revaluations->preview($asOf);
            $previewError = null;
        } catch (ValidationException $exception) {
            $preview = [];
            $previewError = collect($exception->errors())->flatten()->first();
        }

        $rates = ExchangeRate::query()->orderBy('currency')->orderByDesc('rate_on')->get();

        return Inertia::render('currencies/index', [
            'currencies' => $rates->groupBy('currency')->map(fn ($rates, string $currency) => [
                'currency' => $currency,
                'latest' => ['date' => $rates->first()?->rate_on->toDateString(), 'rate' => Currencies::normalize((string) $rates->first()?->rate)],
                'rates' => $rates->take(12)->map(fn (ExchangeRate $rate) => ['id' => $rate->id, 'date' => $rate->rate_on->toDateString(), 'rate' => Currencies::normalize($rate->rate)])->values(),
            ])->values(),
            'accounts' => Account::query()->whereNotNull('currency')->orderBy('code')->get()->map(fn (Account $account) => ['label' => $account->label(), 'currency' => $account->currency]),
            'revaluations' => Revaluation::query()->with(['journal', 'creator'])->orderByDesc('as_of')->limit(24)->get()->map(fn (Revaluation $revaluation) => [
                'id' => $revaluation->id,
                'asOf' => $revaluation->as_of->toDateString(),
                'journalId' => $revaluation->journal_id,
                'journalNumber' => $revaluation->journal?->number,
                'reversalJournalId' => $revaluation->reversal_journal_id,
                'by' => $revaluation->creator->name,
            ]),
            'asOf' => $asOf->toDateString(),
            'preview' => $preview,
            'previewError' => $previewError,
            'revalued' => Revaluation::query()->whereDate('as_of', $asOf)->exists(),
            'booksCurrency' => Money::currency(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function storeRate(Request $request, Currencies $currencies): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'rate_on' => ['required', 'date_format:Y-m-d'],
            'rate' => ['required', 'string', Currencies::RATE_RULE],
        ]);

        $rate = $currencies->setRate((string) $data['currency'], CarbonImmutable::parse($data['rate_on']), (string) $data['rate'], $this->user($request));

        return back()->with('success', "1 {$rate->currency} = ".Currencies::normalize($rate->rate).' '.Money::currency()." from {$rate->rate_on->format('j M Y')}.");
    }

    public function destroyRate(ExchangeRate $rate): RedirectResponse
    {
        $rate->delete();

        return back()->with('success', "{$rate->currency} rate of {$rate->rate_on->format('j M Y')} removed.");
    }

    public function revalue(Request $request, Revaluations $revaluations): RedirectResponse
    {
        $data = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']]);

        $revaluation = $revaluations->run(CarbonImmutable::parse($data['as_of']), $this->user($request));

        return back()->with('success', $revaluation->journal_id === null
            ? 'Nothing to revalue at '.$revaluation->as_of->format('j M Y').'.'
            : 'Revalued at '.$revaluation->as_of->format('j M Y').' and reversed the next day.');
    }
}
