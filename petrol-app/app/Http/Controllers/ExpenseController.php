<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSchedule;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Petrol\Cashbook;
use App\Petrol\Currencies;
use App\Petrol\ExpenseCycles;
use App\Petrol\Permissions;
use App\Petrol\Rates;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

class ExpenseController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $branchId = $this->branchId();

        return Inertia::render('expenses/index', [
            'expenses' => Expense::query()->where('branch_id', $branchId)->latest('incurred_on')->limit(60)->get()->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'description' => $expense->description,
                'amountMinor' => $expense->amount_minor,
                'paidFrom' => $expense->paid_from,
                'incurredOn' => $expense->incurred_on->toDateString(),
            ]),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->where('active', true)->whereIn('kind', [PaymentMethod::CASH, PaymentMethod::BANK])->orderBy('name')->get(['id', 'name', 'kind']),
            'openShift' => Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->exists(),
            'categories' => ExpenseCategory::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'default_type']),
            'schedules' => ExpenseSchedule::query()->where('branch_id', $branchId)->orderBy('next_due_on')->get(),
            'canManage' => $this->canHere(Permissions::EXPENSES_MANAGE),
            'canCategorise' => $this->canCategorise(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Cashbook $cashbook): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'paid_from' => ['required', Rule::in([Expense::CASH, Expense::BANK, Expense::PETTY])],
            'payee' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $category = ExpenseCategory::query()->whereKey($validated['category_id'])->where('active', true)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Choose an approved category.']);
        }
        $currency = strtoupper($validated['currency'] ?? Money::currency());
        if ($currency !== Money::currency() && $validated['paid_from'] !== Expense::BANK) {
            throw ValidationException::withMessages(['paid_from' => 'A foreign-currency expense is paid from the bank, not the drawer.']);
        }
        $minor = Money::input($validated['amount'], $currency);
        $snapshot = app(Rates::class)->snapshot($this->branchId(), $currency, $minor);
        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();
        $expense = $cashbook->expense(
            $this->branchId(),
            $shift,
            $category->name,
            trim($validated['description']),
            $minor,
            $validated['paid_from'],
            $this->member($request),
            $this->text($validated['idempotency_key'] ?? null),
            [
                'category_id' => $category->id,
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_amount_minor' => $snapshot['reporting_minor'],
                'payee' => $this->text($validated['payee'] ?? null),
            ],
        );

        return back()->with('success', $expense->paid_from === Expense::CASH
            ? 'Drawer expense recorded. Finance was told the cash left the drawer.'
            : 'Expense recorded.');
    }

    public function category(Request $request): RedirectResponse
    {
        abort_unless($this->canCategorise(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'default_type' => ['required', Rule::in([ExpenseCategory::ONCE, ExpenseCategory::RECURRING])],
        ]);
        ExpenseCategory::query()->create([
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'description' => $this->text($validated['description'] ?? null),
            'default_type' => $validated['default_type'],
            'active' => true,
        ]);

        return back()->with('success', 'Category added for every station.');
    }

    public function schedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'uuid'],
            'frequency' => ['required', Rule::in(ExpenseSchedule::FREQUENCIES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'payee' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        $category = ExpenseCategory::query()->whereKey($validated['category_id'])->where('active', true)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Choose an approved category.']);
        }
        ExpenseSchedule::query()->create([
            'branch_id' => $this->branchId(),
            'category_id' => $category->id,
            'frequency' => $validated['frequency'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
            'next_due_on' => $validated['starts_on'],
            'amount_minor' => Money::input($validated['amount'], $validated['currency'] ?? null),
            'currency' => strtoupper($validated['currency'] ?? Money::currency()),
            'payee' => $this->text($validated['payee'] ?? null),
            'description' => trim($validated['description']),
            'active' => true,
            'status' => ExpenseSchedule::ACTIVE,
            'created_by' => $this->member($request)->id,
        ]);

        return back()->with('success', 'Schedule saved. It is not an expense until a due date is generated.');
    }

    public function generate(Request $request, ExpenseCycles $cycles): RedirectResponse
    {
        $made = $cycles->generateDue($this->member($request), $this->branchId());

        return back()->with('success', $made === 0 ? 'Nothing is due.' : $made.' expense'.($made === 1 ? '' : 's').' recorded from schedules. They are not marked paid.');
    }

    public function pause(ExpenseSchedule $schedule, ExpenseCycles $cycles): RedirectResponse
    {
        $this->ensureHere($schedule->branch_id);
        $cycles->pause($schedule);

        return back()->with('success', 'Schedule paused. Due dates already recorded stay on the books.');
    }

    public function resume(ExpenseSchedule $schedule, ExpenseCycles $cycles): RedirectResponse
    {
        $this->ensureHere($schedule->branch_id);
        $cycles->resume($schedule);

        return back()->with('success', 'Schedule resumed. Missed dates are recorded the next time due expenses are generated.');
    }

    public function end(ExpenseSchedule $schedule, ExpenseCycles $cycles): RedirectResponse
    {
        $this->ensureHere($schedule->branch_id);
        $cycles->end($schedule);

        return back()->with('success', 'Schedule ended.');
    }

    public function approve(Expense $expense): RedirectResponse
    {
        $this->ensureHere($expense->branch_id);
        if ($expense->approval_status !== 'approved') {
            $expense->approval_status = 'approved';
            $expense->save();
        }

        return back()->with('success', 'Expense approved.');
    }

    private function canCategorise(): bool
    {
        $access = app(CurrentAccess::class)->get();

        return $access !== null
            && $access->allowsIn(Permissions::EXPENSE_CATEGORIES)
            && $access->branchesFor(Permissions::EXPENSE_CATEGORIES) === null;
    }
}
