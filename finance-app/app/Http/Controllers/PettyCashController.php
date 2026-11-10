<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Finance\Money;
use App\Finance\PettyCash;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\PaymentRequest;
use App\Models\PettyCashCount;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Petty cash funds: set up, paid from, checked, counted, replenished and
 * closed. The rules are in App\Finance\PettyCash; routes check permissions.
 */
class PettyCashController extends Controller
{
    /** What a receipt may be: a photo or a PDF, up to 5 MB. */
    private const RECEIPT_RULES = ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp,heic'];

    public function index(Request $request): Response
    {
        $funds = PettyCashFund::query()->with(['account', 'custodian'])->orderBy('name')->get();
        $open = $this->openReplenishments();
        $unchecked = PettyCashVoucher::query()->where('status', PettyCashVoucher::RECORDED)
            ->groupBy('fund_id')->selectRaw('fund_id, COUNT(*) AS open')->toBase()->pluck('open', 'fund_id');

        return Inertia::render('petty-cash/index', [
            'funds' => $funds->map(fn (PettyCashFund $fund) => $this->summary($fund, $open, (int) ($unchecked[$fund->id] ?? 0)))->values(),
            'accounts' => Account::query()->where('bank_kind', BankKind::PettyCash)->where('active', true)
                ->whereNotIn('id', PettyCashFund::query()->select('account_id'))
                ->orderBy('code')->get()
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label(), 'currency' => $account->currency])
                ->values(),
            'canManage' => $this->user($request)->can('finance.pettycash.manage'),
            'limit' => $this->limitText(),
        ]);
    }

    public function store(Request $request, PettyCash $pettyCash, PlatformApi $platform): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'account_id' => ['required', 'uuid', $this->ours('accounts')],
            'custodian' => ['required', 'email', 'max:255'],
            'float' => ['required', 'string', Money::rule()],
            'replenish_at' => ['nullable', 'string', Money::rule()],
        ]);
        $account = Account::query()->findOrFail((string) $data['account_id']);

        $fund = $pettyCash->createFund(
            $data['name'],
            $account,
            $this->member((string) $data['custodian'], $platform),
            $this->amountIn((string) $data['float'], $account->currency, 'float'),
            ($data['replenish_at'] ?? '') === '' ? null : $this->amountIn((string) $data['replenish_at'], $account->currency, 'replenish_at'),
            $this->user($request),
        );

        return redirect()->route('petty-cash.show', $fund)->with('success', "{$fund->name} set up. Ask for its float to fund it.");
    }

    public function show(Request $request, PettyCashFund $fund): Response
    {
        $user = $this->user($request);
        $fund->load(['account', 'custodian']);
        $isCustodian = $fund->custodian_id === $user->id;
        $vouchers = $fund->vouchers()->with(['account', 'costCentre', 'project', 'recorder', 'checker', 'replenishment', 'journal'])
            ->orderByDesc('created_at')->limit(200)->get();
        $currency = $fund->account->currency;

        return Inertia::render('petty-cash/show', [
            'fund' => $this->summary($fund, $this->openReplenishments(), $vouchers->where('status', PettyCashVoucher::RECORDED)->count()),
            'vouchers' => $vouchers->map(fn (PettyCashVoucher $voucher) => [
                'id' => $voucher->id,
                'number' => $voucher->number,
                'paidOn' => $voucher->paid_on->toDateString(),
                'amountMinor' => $voucher->amount_minor,
                'payee' => $voucher->payee,
                'description' => $voucher->description,
                'account' => $voucher->account->label(),
                'costCentre' => $voucher->costCentre?->label(),
                'project' => $voucher->project?->label(),
                'status' => $voucher->status,
                'recordedBy' => $voucher->recorder->name,
                'checkedBy' => $voucher->checker?->name,
                'rejectNote' => $voucher->reject_note,
                'replenishment' => $voucher->replenishment?->number,
                'journalId' => $voucher->journal_id,
                'journal' => $voucher->journal->number,
                'receiptName' => $voucher->receipt_name,
                'canCheck' => $voucher->status === PettyCashVoucher::RECORDED && ! $isCustodian && $voucher->recorded_by !== $user->id && $user->can('finance.payments.review'),
            ])->values(),
            'counts' => $fund->counts()->with('counter')->orderByDesc('created_at')->limit(50)->get()->map(fn (PettyCashCount $count) => [
                'id' => $count->id,
                'countedOn' => $count->counted_on->toDateString(),
                'countedMinor' => $count->counted_minor,
                'bookMinor' => $count->book_minor,
                'differenceMinor' => $count->difference_minor,
                'note' => $count->note,
                'by' => $count->counter->name,
            ])->values(),
            'banks' => Account::query()->banks()->where('active', true)
                ->where('bank_kind', '!=', BankKind::PettyCash)
                ->where(fn ($query) => $currency === null ? $query->whereNull('currency') : $query->where('currency', $currency))
                ->orderBy('code')->get()
                ->map(fn (Account $bank) => ['id' => $bank->id, 'label' => $bank->label()])->values(),
            'accounts' => Account::query()->where('active', true)->whereNull('bank_kind')
                ->where(fn ($query) => $query->where('type', AccountType::Expense)->orWhere('system_key', Account::EMPLOYEE_ADVANCES))
                ->orderBy('code')->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                    'requires' => array_keys(array_filter([DimensionValue::COST_CENTRE => $account->requires_cost_centre, DimensionValue::PROJECT => $account->requires_project])),
                ])->values(),
            'dimensions' => $this->dimensionOptions(),
            'can' => [
                'pay' => $isCustodian && $fund->status === PettyCashFund::ACTIVE && $user->can('finance.pettycash.record'),
                'replenish' => $fund->status !== PettyCashFund::CLOSED && (($isCustodian && $user->can('finance.pettycash.record')) || $user->can('finance.pettycash.manage')),
                'count' => $fund->status !== PettyCashFund::CLOSED && ! $isCustodian && $user->can('finance.banking.reconcile'),
                'manage' => $fund->status !== PettyCashFund::CLOSED && $user->can('finance.pettycash.manage'),
            ],
            'isCustodian' => $isCustodian,
            'limit' => $this->limitText(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function update(Request $request, PettyCashFund $fund, PettyCash $pettyCash): RedirectResponse
    {
        $data = $request->validate([
            'float' => ['required', 'string', Money::rule()],
            'replenish_at' => ['nullable', 'string', Money::rule()],
            'status' => ['required', Rule::in([PettyCashFund::ACTIVE, PettyCashFund::SUSPENDED])],
        ]);
        $currency = $fund->account()->value('currency');

        $pettyCash->update(
            $fund,
            $this->amountIn((string) $data['float'], is_string($currency) ? $currency : null, 'float'),
            ($data['replenish_at'] ?? '') === '' ? null : $this->amountIn((string) $data['replenish_at'], is_string($currency) ? $currency : null, 'replenish_at'),
            (string) $data['status'],
            $this->user($request),
        );

        return back()->with('success', "{$fund->name} saved.");
    }

    public function custodian(Request $request, PettyCashFund $fund, PettyCash $pettyCash, PlatformApi $platform): RedirectResponse
    {
        $data = $request->validate(['custodian' => ['required', 'email', 'max:255']]);
        $custodian = $this->member((string) $data['custodian'], $platform);
        $pettyCash->changeCustodian($fund, $custodian, $this->user($request));

        return back()->with('success', "{$custodian->name} now holds {$fund->name}.");
    }

    public function close(Request $request, PettyCashFund $fund, PettyCash $pettyCash): RedirectResponse
    {
        $pettyCash->close($fund, $this->user($request));

        return back()->with('success', "{$fund->name} closed.");
    }

    public function pay(Request $request, PettyCashFund $fund, PettyCash $pettyCash): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', Money::rule()],
            'payee' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:200'],
            'account_id' => ['required', 'uuid', $this->openAccount(bank: false)],
            'cost_centre_id' => ['nullable', 'uuid', $this->ours('dimension_values')->where('kind', DimensionValue::COST_CENTRE)->where('active', true)],
            'project_id' => ['nullable', 'uuid', $this->ours('dimension_values')->where('kind', DimensionValue::PROJECT)->where('active', true)],
            'receipt' => self::RECEIPT_RULES,
        ]);
        $currency = $fund->account()->value('currency');

        $voucher = $pettyCash->pay($fund, [
            'paid_on' => CarbonImmutable::parse((string) $data['paid_on']),
            'amount_minor' => $this->amountIn((string) $data['amount'], is_string($currency) ? $currency : null),
            'payee' => $data['payee'] ?? null,
            'description' => (string) $data['description'],
            'account' => Account::query()->findOrFail((string) $data['account_id']),
            'cost_centre' => isset($data['cost_centre_id']) ? DimensionValue::query()->findOrFail((string) $data['cost_centre_id']) : null,
            'project' => isset($data['project_id']) ? DimensionValue::query()->findOrFail((string) $data['project_id']) : null,
        ], $request->file('receipt'), $this->user($request));

        return back()->with('success', "{$voucher->number} recorded; it waits to be checked.");
    }

    public function receipt(PettyCashFund $fund, PettyCashVoucher $voucher): StreamedResponse
    {
        $disk = Storage::disk(PettyCash::RECEIPT_DISK);
        abort_unless($disk->exists($voucher->receipt_path), 404);

        return $disk->download($voucher->receipt_path, $voucher->receipt_name, ['Content-Type' => $voucher->receipt_mime]);
    }

    public function verify(Request $request, PettyCashFund $fund, PettyCashVoucher $voucher, PettyCash $pettyCash): RedirectResponse
    {
        $pettyCash->verify($voucher, $this->user($request));

        return back()->with('success', "{$voucher->number} verified.");
    }

    public function reject(Request $request, PettyCashFund $fund, PettyCashVoucher $voucher, PettyCash $pettyCash): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $pettyCash->reject($voucher, (string) $data['note'], $this->user($request));

        return back()->with('success', "{$voucher->number} rejected and reversed; the custodian owes the fund.");
    }

    public function count(Request $request, PettyCashFund $fund, PettyCash $pettyCash): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'string', Money::rule()],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $currency = $fund->account()->value('currency');
        $count = $pettyCash->count($fund, $this->amountIn((string) $data['counted'], is_string($currency) ? $currency : null, 'counted'), $data['note'] ?? null, $this->user($request));

        return back()->with('success', $count->difference_minor === 0 ? 'The count agrees with the books.' : 'Count recorded; the difference is posted to Cash over and short.');
    }

    public function replenish(Request $request, PettyCashFund $fund, PettyCash $pettyCash): RedirectResponse
    {
        $user = $this->user($request);
        // Its custodian, or whoever manages funds.
        abort_unless($user->can('finance.pettycash.manage') || $fund->custodian_id === $user->id, 403);
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'pay_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $payment = $pettyCash->replenish($fund, Account::query()->findOrFail((string) $data['account_id']), CarbonImmutable::parse((string) $data['pay_on']), $user);

        return back()->with('success', $payment->status === PaymentRequest::APPROVED
            ? "{$payment->number} is below the approval limit and ready to disburse."
            : "{$payment->number} raised; it goes through approval like any payment.");
    }

    public function limit(Request $request, PettyCash $pettyCash): RedirectResponse
    {
        $data = $request->validate(['petty_cash_max' => ['nullable', 'string', Money::rule()]]);
        $pettyCash->setLimit(($data['petty_cash_max'] ?? '') === '' ? null : Money::toMinor((string) $data['petty_cash_max']), $this->user($request));

        return back()->with('success', 'Petty cash limit saved.');
    }

    /**
     * A member of this organization who may hold petty cash, by email, as the
     * platform sees them now.
     *
     * @throws ValidationException
     */
    private function member(string $email, PlatformApi $platform): User
    {
        $user = User::query()->where('email', $email)->first();
        $refused = ValidationException::withMessages(['custodian' => 'They must be a member who has signed in to Finance and holds a role that keeps petty cash (Cashier or Finance Officer).']);
        if ($user === null) {
            throw $refused;
        }

        try {
            $access = $platform->effectiveAccess($this->organizationId(), $user->id);
        } catch (NotVisible) {
            throw $refused;
        } catch (PlatformUnavailable) {
            throw ValidationException::withMessages(['custodian' => 'The platform could not be reached to check them; try again.']);
        }
        if (! $access->allowed || ! ($access->isOwner || $access->has('finance.pettycash.record'))) {
            throw $refused;
        }

        return $user;
    }

    /**
     * @return array<string, PaymentRequest> fund id => its open replenishment
     */
    private function openReplenishments(): array
    {
        $open = [];
        foreach (PaymentRequest::query()->where('kind', PaymentRequest::TRANSFER)->whereIn('status', PaymentRequest::OPEN)->get() as $request) {
            if (is_string($fundId = $request->details['petty_cash_fund_id'] ?? null)) {
                $open[$fundId] = $request;
            }
        }

        return $open;
    }

    /**
     * @param  array<string, PaymentRequest>  $open
     * @return array<string, mixed>
     */
    private function summary(PettyCashFund $fund, array $open, int $unchecked): array
    {
        $held = $fund->account->heldMinor();
        $request = $open[$fund->id] ?? null;

        return [
            'id' => $fund->id,
            'name' => $fund->name,
            'account' => $fund->account->label(),
            'currency' => $fund->account->currency,
            'custodian' => $fund->custodian?->name,
            'custodianEmail' => $fund->custodian?->email,
            'movedIn' => $fund->source_reference !== null,
            'floatMinor' => $fund->float_minor,
            'floatMajor' => Money::toMajor($fund->float_minor, $fund->account->currency),
            'replenishAtMajor' => $fund->replenish_at_minor === null ? '' : Money::toMajor($fund->replenish_at_minor, $fund->account->currency),
            'replenishAtMinor' => $fund->replenish_at_minor,
            'heldMinor' => $held,
            'status' => $fund->status,
            'due' => PettyCash::due($fund, $held),
            'unchecked' => $unchecked,
            'replenishment' => $request === null ? null : ['number' => $request->number, 'status' => $request->status, 'amountMinor' => $request->amount_minor],
        ];
    }

    private function limitText(): ?string
    {
        $limit = Workspace::current()?->petty_cash_max_minor;

        return $limit === null ? null : Money::toMajor($limit);
    }
}
