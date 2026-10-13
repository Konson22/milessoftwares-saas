<?php

namespace App\Http\Controllers;

use App\Finance\Money;
use App\Finance\Payables;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier bills: entered here, or received from products.
 */
class BillController extends Controller
{
    public const STATUSES = ['unpaid', 'overdue', 'paid', 'all'];

    /**
     * @return array<string, mixed>
     */
    public static function row(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'supplier' => $bill->relationLoaded('supplier') ? $bill->supplier->name : null,
            'supplierReference' => $bill->supplier_reference,
            'source' => $bill->source_app,
            'billDate' => $bill->bill_date->toDateString(),
            'dueOn' => $bill->due_on?->toDateString(),
            'totalMinor' => $bill->total_minor,
            'paidMinor' => $bill->paid_minor,
            'outstandingMinor' => $bill->outstanding(),
            'status' => $bill->status(),
        ];
    }

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'unpaid';
        $search = trim((string) $request->query('search', ''));

        $bills = Bill::query()
            ->with('supplier')
            ->when($status === 'unpaid', fn ($query) => $query->whereColumn('paid_minor', '<', 'total_minor'))
            ->when($status === 'overdue', fn ($query) => $query->whereColumn('paid_minor', '<', 'total_minor')->whereDate('due_on', '<', CarbonImmutable::today()))
            ->when($status === 'paid', fn ($query) => $query->whereColumn('paid_minor', '>=', 'total_minor'))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhere('supplier_reference', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('bill_date')
            ->orderByDesc('number')
            ->limit(500)
            ->get();

        return Inertia::render('bills/index', [
            'status' => $status,
            'search' => $search,
            'bills' => $bills->map(fn (Bill $bill) => self::row($bill)),
            'outstandingMinor' => (int) Bill::query()->selectRaw('COALESCE(SUM(total_minor - paid_minor), 0) AS owed')->value('owed'),
        ]);
    }

    public function show(Bill $bill): Response
    {
        $bill->load('supplier');
        $payments = BillPayment::query()
            ->where('bill_id', $bill->id)
            ->join('supplier_payments', 'supplier_payments.id', '=', 'bill_payments.supplier_payment_id')
            ->orderBy('supplier_payments.paid_on')
            ->get(['bill_payments.amount_minor', 'supplier_payments.number', 'supplier_payments.paid_on', 'supplier_payments.journal_id']);

        return Inertia::render('bills/show', [
            'bill' => self::row($bill) + [
                'supplierId' => $bill->supplier_id,
                'taxMinor' => $bill->tax_minor,
                'memo' => $bill->memo,
                'journalId' => $bill->journal_id,
                'lines' => $bill->lines,
            ],
            'payments' => $payments->map(fn (BillPayment $payment) => [
                'number' => (string) $payment->getAttribute('number'),
                'paidOn' => substr((string) $payment->getAttribute('paid_on'), 0, 10),
                'amountMinor' => $payment->amount_minor,
                'journalId' => (string) $payment->getAttribute('journal_id'),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('bills/create', [
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'supplierId' => $request->query('supplier'),
            'accounts' => $this->accountOptions(bank: false),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', $this->ours('suppliers')],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'supplier_reference' => ['nullable', 'string', 'max:64'],
            'memo' => ['nullable', 'string', 'max:255'],
            'tax' => ['nullable', 'string', Money::rule()],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.account_id' => ['required', 'uuid', $this->openAccount(bank: false)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'string', Money::rule(), 'not_regex:/^0+(\.0+)?$/'],
        ]);

        $accounts = Account::query()->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $bill = $payables->recordBill(
            Supplier::query()->findOrFail((string) $data['supplier_id']),
            CarbonImmutable::parse($data['bill_date']),
            isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null,
            $data['supplier_reference'] ?? null,
            array_values(array_map(fn (array $line) => [
                'account' => $accounts->get($line['account_id']) ?? abort(422),
                'description' => $line['description'],
                'amount_minor' => Money::toMinor($line['amount']),
            ], $data['lines'])),
            ($data['tax'] ?? '') === '' ? 0 : Money::toMinor($data['tax']),
            $data['memo'] ?? null,
            $this->user($request),
        );

        return redirect()->route('bills.show', $bill)->with('success', "Bill {$bill->number} recorded.");
    }
}
