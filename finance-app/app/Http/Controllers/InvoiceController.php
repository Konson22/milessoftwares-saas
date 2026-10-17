<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Finance\Money;
use App\Finance\Receivables;
use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer invoices: raised here, or products' sales.
 */
class InvoiceController extends Controller
{
    public const STATUSES = ['unpaid', 'overdue', 'paid', 'void', 'all'];

    /**
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'customer' => $invoice->customer_name,
            'source' => $invoice->source_app,
            'issuedOn' => $invoice->issued_on->toDateString(),
            'dueOn' => $invoice->due_on?->toDateString(),
            'totalMinor' => $invoice->total_minor,
            'paidMinor' => $invoice->paid_minor,
            'creditedMinor' => $invoice->credited_minor,
            'outstandingMinor' => $invoice->outstanding(),
            'status' => $invoice->status(),
        ];
    }

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'unpaid';
        $search = trim((string) $request->query('search', ''));

        $invoices = Invoice::query()
            ->when($status === 'unpaid', fn ($query) => $query->whereNull('voided_at')->whereRaw('paid_minor + credited_minor < total_minor'))
            ->when($status === 'overdue', fn ($query) => $query->whereNull('voided_at')->whereRaw('paid_minor + credited_minor < total_minor')->whereDate('due_on', '<', CarbonImmutable::today()))
            ->when($status === 'paid', fn ($query) => $query->whereNull('voided_at')->whereRaw('paid_minor + credited_minor >= total_minor'))
            ->when($status === 'void', fn ($query) => $query->whereNotNull('voided_at'))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->orderByDesc('issued_on')
            ->orderByDesc('number')
            ->limit(500)
            ->get();

        return Inertia::render('invoices/index', [
            'status' => $status,
            'search' => $search,
            'invoices' => $invoices->map(fn (Invoice $invoice) => self::row($invoice)),
            'outstandingMinor' => (int) Invoice::query()->whereNull('voided_at')->selectRaw('COALESCE(SUM(total_minor - credited_minor - paid_minor), 0) AS owed')->value('owed'),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $payments = $invoice->payments()->with('account')->orderBy('received_on')->orderBy('created_at')->get();

        return Inertia::render('invoices/show', [
            'invoice' => self::row($invoice) + [
                'taxMinor' => $invoice->tax_minor,
                'journalId' => $invoice->journal_id,
                'voidJournalId' => $invoice->void_journal_id,
                'voidReason' => $invoice->void_reason,
                'voidable' => $invoice->source_app === 'finance' && $invoice->voided_at === null && $invoice->paid_minor === 0,
                'lines' => $invoice->lines,
            ],
            'payments' => $payments->map(fn (CustomerPayment $payment) => CustomerPaymentController::row($payment)),
            'banks' => $this->accountOptions(bank: true),
            'methods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('invoices/create', [
            'accounts' => array_values(array_filter($this->accountOptions(bank: false), fn (array $account) => $account['type'] === AccountType::Revenue->value)),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, Receivables $receivables): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'tax' => ['nullable', 'string', Money::rule()],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.account_id' => ['required', 'uuid', $this->openAccount(bank: false)->where('type', AccountType::Revenue->value)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'string', Money::rule(), 'not_regex:/^0+(\.0+)?$/'],
        ]);

        $accounts = Account::query()->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $invoice = $receivables->raise(
            $data['customer_name'],
            CarbonImmutable::parse($data['issued_on']),
            isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null,
            array_values(array_map(fn (array $line) => [
                'account' => $accounts->get($line['account_id']) ?? abort(422),
                'description' => $line['description'],
                'amount_minor' => Money::toMinor($line['amount']),
            ], $data['lines'])),
            ($data['tax'] ?? '') === '' ? 0 : Money::toMinor($data['tax']),
            $this->user($request),
        );

        return redirect()->route('invoices.show', $invoice)->with('success', "Invoice {$invoice->number} raised.");
    }

    public function void(Request $request, Invoice $invoice, Receivables $receivables): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $receivables->void($invoice, $data['reason'], $this->user($request));

        return back()->with('success', "Invoice {$invoice->number} voided.");
    }
}
