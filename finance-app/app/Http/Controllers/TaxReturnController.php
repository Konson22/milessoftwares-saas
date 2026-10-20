<?php

namespace App\Http\Controllers;

use App\Finance\Tax;
use App\Models\Account;
use App\Models\TaxReturn;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tax returns: the next period's report as it stands, filing it, paying it
 * (or taking in its refund), and unfiling the latest.
 */
class TaxReturnController extends Controller
{
    public function index(Request $request, Tax $tax): Response
    {
        $next = $tax->nextStart();
        $preview = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $next !== null && $tax->latest() !== null
            ? $next
            : (isset($preview['starts_on']) ? CarbonImmutable::parse($preview['starts_on']) : $next);
        $to = isset($preview['ends_on'])
            ? CarbonImmutable::parse($preview['ends_on'])
            : ($from ?? CarbonImmutable::today())->endOfMonth()->startOfDay()->min(CarbonImmutable::yesterday());

        return Inertia::render('tax-returns/index', [
            'returns' => TaxReturn::query()->orderByDesc('ends_on')->get()->map(fn (TaxReturn $return) => $this->row($return)),
            'firstReturn' => $tax->latest() === null,
            'preview' => $from === null || $to->lessThan($from) ? null : $tax->report($from, $to),
            'startsOn' => $from?->toDateString(),
            'endsOn' => $to->toDateString(),
        ]);
    }

    public function store(Request $request, Tax $tax): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);

        $return = $tax->file(
            isset($data['starts_on']) ? CarbonImmutable::parse($data['starts_on']) : null,
            CarbonImmutable::parse($data['ends_on']),
            $data['reference'] ?? null,
            $this->user($request),
        );

        return redirect()->route('tax-returns.show', $return)->with('success', 'Tax return filed: '.$this->title($return).'.');
    }

    public function show(Request $request, TaxReturn $return, Tax $tax): Response|StreamedResponse
    {
        $report = $tax->report($return->starts_on, $return->ends_on);

        if ($request->query('export') === 'rows') {
            return CsvExport::download('tax-return-'.$return->starts_on->toDateString().'-to-'.$return->ends_on->toDateString(), [
                'Kind' => 'kind',
                'Code' => 'code',
                'Net amount' => 'baseMinor',
                'Tax' => 'taxMinor',
            ], array_map(fn (array $row) => [
                'kind' => $row['kind'] ?? 'adjustment',
                'code' => $row['code'],
                'baseMinor' => $row['base_minor'],
                'taxMinor' => $row['tax_minor'],
            ], $report['rows']));
        }

        $return->load(['filer', 'settlementJournal']);

        return Inertia::render('tax-returns/show', [
            'taxReturn' => $this->row($return) + [
                'filedBy' => $return->filer->name,
                'settlementNumber' => $return->settlementJournal?->number,
                'latest' => $tax->latest()?->id === $return->id,
            ],
            'report' => $report,
            // Posted since filing (a manual journal before the lock, say): the
            // filed totals no longer match the ledger.
            'changed' => $report['net_minor'] !== $return->net_minor,
            'banks' => $this->accountOptions(bank: true),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function settle(Request $request, TaxReturn $return, Tax $tax): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $tax->settle($return, Account::query()->whereKey($data['account_id'])->firstOrFail(), CarbonImmutable::parse($data['date']), $this->user($request));

        return back()->with('success', 'Settlement posted.');
    }

    public function destroy(Request $request, TaxReturn $return, Tax $tax): RedirectResponse
    {
        $tax->unfile($return, $this->user($request));

        return redirect()->route('tax-returns.index')->with('success', 'Tax return unfiled: '.$this->title($return).'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TaxReturn $return): array
    {
        return [
            'id' => $return->id,
            'title' => $this->title($return),
            'startsOn' => $return->starts_on->toDateString(),
            'endsOn' => $return->ends_on->toDateString(),
            'reference' => $return->reference,
            'outputMinor' => $return->output_minor,
            'inputMinor' => $return->input_minor,
            'adjustmentsMinor' => $return->adjustments_minor,
            'netMinor' => $return->net_minor,
            'filedAt' => $return->filed_at->toIso8601String(),
            'settled' => $return->settlement_journal_id !== null,
            'settlementJournalId' => $return->settlement_journal_id,
        ];
    }

    private function title(TaxReturn $return): string
    {
        return $return->starts_on->format('j M Y').' to '.$return->ends_on->format('j M Y');
    }
}
