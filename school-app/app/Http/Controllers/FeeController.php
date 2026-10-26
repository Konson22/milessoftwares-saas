<?php

namespace App\Http\Controllers;

use App\Actions\Fees;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A term's fees: what each class pays, and billing the students.
 */
class FeeController extends Controller
{
    public function index(Request $request): Response
    {
        $term = $this->term($request);
        $items = $term === null ? collect() : FeeItem::query()->where('term_id', $term->id)->with('schoolClass:id,name')->orderBy('name')->get();
        $invoices = $term === null ? null : Invoice::query()->where('term_id', $term->id)->where('status', '!=', Invoice::VOIDED);

        return Inertia::render('fees/index', [
            'filters' => ['term' => $term?->id],
            'terms' => $this->termOptions(),
            'items' => $items->map(fn (FeeItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'className' => $item->schoolClass?->name,
                'amountMinor' => $item->amount_minor,
            ]),
            'classes' => $this->classOptions(),
            'summary' => $invoices === null ? null : [
                'invoices' => (clone $invoices)->count(),
                'billedMinor' => (int) (clone $invoices)->sum('total_minor'),
                'paidMinor' => (int) (clone $invoices)->sum('paid_minor'),
                'notBilled' => Student::query()->where('status', Student::ACTIVE)->whereNotNull('school_class_id')
                    ->whereNotIn('id', (clone $invoices)->select('student_id'))->count(),
            ],
            'today' => today()->toDateString(),
            'canManage' => $this->can($request, 'school.fees.manage'),
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'term_id' => ['required', 'uuid'],
            'school_class_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule()],
        ]);
        $term = Term::query()->whereKey($validated['term_id'])->first() ?? throw ValidationException::withMessages(['term_id' => 'Choose a term.']);
        $class = isset($validated['school_class_id']) ? SchoolClass::query()->whereKey($validated['school_class_id'])->first() : null;
        if (isset($validated['school_class_id']) && $class === null) {
            throw ValidationException::withMessages(['school_class_id' => 'Choose a class of the school.']);
        }

        $item = FeeItem::query()->create([
            'term_id' => $term->id,
            'school_class_id' => $class?->id,
            'name' => trim($validated['name']),
            'amount_minor' => Money::toMinor($validated['amount']),
        ]);

        return back()->with('success', "{$item->name} (".Money::format($item->amount_minor).') added for '.($class->name ?? 'every class').'.');
    }

    public function destroyItem(FeeItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', "{$item->name} removed: invoices already issued keep it.");
    }

    public function bill(Request $request, Fees $fees): RedirectResponse
    {
        $validated = $request->validate([
            'term_id' => ['required', 'uuid'],
            'school_class_id' => ['nullable', 'uuid'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $term = Term::query()->whereKey($validated['term_id'])->first() ?? throw ValidationException::withMessages(['term_id' => 'Choose a term.']);
        $class = isset($validated['school_class_id']) ? SchoolClass::query()->whereKey($validated['school_class_id'])->first() : null;
        if (isset($validated['school_class_id']) && $class === null) {
            throw ValidationException::withMessages(['school_class_id' => 'Choose a class of the school.']);
        }

        $issued = $fees->bill($term, $class, isset($validated['due_on']) ? CarbonImmutable::parse($validated['due_on']) : null, $this->member($request));

        return back()->with('success', count($issued) === 0
            ? 'Everyone is already billed for this term.'
            : count($issued).' '.(count($issued) === 1 ? 'invoice' : 'invoices').' issued, '.Money::format(array_sum(array_map(fn (Invoice $invoice) => $invoice->total_minor, $issued))).' in all.');
    }
}
