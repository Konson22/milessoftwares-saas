<?php

namespace App\Http\Controllers;

use App\Procurement\Access;
use App\Procurement\Models\Award;
use App\Procurement\Models\EvaluationCriterion;
use App\Procurement\Models\EvaluationScore;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\QuotationLine;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\SourcingItem;
use App\Procurement\Models\Supplier;
use App\Procurement\Money;
use App\Procurement\Sourcing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Money\EntryCurrencies;

/**
 * Sourcing events, their quotations, evaluation and award.
 */
class SourcingController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        return Inertia::render('sourcing/index', [
            'status' => $status,
            'events' => SourcingEvent::query()->with('requisition:id,number')->withCount(['quotations' => fn ($q) => $q->where('status', 'received')])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest()->limit(200)->get()
                ->map(fn (SourcingEvent $e) => [
                    'id' => $e->id, 'number' => $e->number, 'title' => $e->title, 'method' => Sourcing::LABELS[$e->method],
                    'status' => $e->status, 'requisition' => $e->requisition->number,
                    'deadline' => self::time($e->quotes_due_at), 'quotations' => $e->getAttribute('quotations_count'),
                ]),
            'statuses' => SourcingEvent::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'requisition_id' => ['required', 'uuid'],
            'method' => ['required', Rule::in(SourcingEvent::METHODS)],
            'title' => ['nullable', 'string', 'max:255'],
            'quotes_due_at' => ['required', 'date', 'after:now'],
            'method_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $event = Sourcing::create(Access::requisition($data['requisition_id']), $data, $this->user());

        return to_route('sourcing.show', $event)->with('success', 'Sourcing event drafted. Invite suppliers and set the evaluation criteria before issuing it.');
    }

    public function show(SourcingEvent $event): Response
    {
        $event->load(['requisition:id,number,title,currency,total_minor', 'items', 'invited', 'criteria']);
        $pricesOpen = $event->pricesOpen();
        $comparison = collect(Sourcing::comparison($event));
        $mine = EvaluationScore::query()->whereIn('quotation_id', $comparison->map(fn ($r) => $r['quotation']->id))->where('evaluator_id', $this->user()->id)->get();
        $award = $event->awards()->with(['supplier:id,name', 'recommender:id,name', 'quotation'])->first();
        $base = Money::base();

        return Inertia::render('sourcing/show', [
            'event' => [
                'id' => $event->id, 'number' => $event->number, 'title' => $event->title, 'description' => $event->getAttribute('description'),
                'method' => $event->method, 'methodLabel' => Sourcing::LABELS[$event->method], 'methodReason' => $event->getAttribute('method_reason'),
                'status' => $event->status, 'statusReason' => $event->getAttribute('status_reason'), 'sealed' => $event->sealed, 'pricesOpen' => $pricesOpen,
                'deliveryLocation' => $event->getAttribute('delivery_location'),
                'quotesDueAt' => self::time($event->quotes_due_at), 'requiredBy' => self::date($event->getAttribute('required_by')),
                'requisition' => ['id' => $event->requisition_id, 'number' => $event->requisition->number, 'title' => $event->requisition->title],
                'items' => $event->items->map(fn (SourcingItem $i) => [
                    'id' => $i->id, 'kind' => $i->kind, 'description' => $i->description, 'specification' => $i->specification,
                    'quantity' => $i->quantity, 'unit' => $i->unit,
                ]),
                'invited' => $event->invited->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status]),
                'criteria' => $event->criteria->map(fn (EvaluationCriterion $c) => [
                    'id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'mandatory' => $c->mandatory, 'maxScore' => $c->max_score,
                ]),
            ],
            'baseCurrency' => $base,
            'quotations' => $comparison->map(function (array $row) use ($pricesOpen, $mine) {
                /** @var Quotation $q */
                $q = $row['quotation'];

                return [
                    'id' => $q->id,
                    'supplier' => $q->supplier->name,
                    'supplierId' => $q->supplier_id,
                    'reference' => $q->getAttribute('reference'),
                    'late' => $q->late,
                    'currency' => $q->currency,
                    'rate' => $q->rate_minor === null ? null : Money::major($q->rate_minor, Money::base()),
                    'total' => $pricesOpen ? self::major($q->total_minor, $q->currency) : null,
                    'totalBase' => $pricesOpen ? self::major($q->total_base_minor, Money::base()) : null,
                    'deliveryDays' => $q->delivery_days,
                    'warranty' => $q->getAttribute('warranty'),
                    'paymentTerms' => $q->getAttribute('payment_terms'),
                    'validUntil' => self::date($q->getAttribute('valid_until')),
                    'lines' => $q->lines->map(fn (QuotationLine $l) => [
                        'itemId' => $l->sourcing_item_id, 'compliant' => $l->compliant, 'note' => $l->note,
                        'unitPrice' => $pricesOpen ? self::major($l->unit_price_minor, $q->currency) : null,
                        'amount' => $pricesOpen ? self::major($l->amount_minor, $q->currency) : null,
                    ]),
                    'compliance' => $row['compliance'],
                    'scores' => $row['scores'],
                    'technical' => $row['technical'],
                    'rank' => $pricesOpen ? $row['rank'] : null,
                    'myScores' => $mine->where('quotation_id', $q->id)->mapWithKeys(fn (EvaluationScore $s) => [$s->criterion_id => ['passed' => $s->passed, 'score' => $s->score, 'note' => $s->getAttribute('note')]]),
                ];
            })->values(),
            'award' => $award === null ? null : [
                'id' => $award->id, 'status' => $award->status, 'supplier' => $award->supplier->name,
                'total' => self::major($award->quotation->total_minor, $award->quotation->currency), 'currency' => $award->quotation->currency,
                'justification' => $award->justification, 'deviationReason' => $award->deviation_reason,
                'recommendedBy' => $award->recommender->name,
                'approval' => $this->approval('award', $award),
                'order' => PurchaseOrder::query()->where('award_id', $award->id)->where('status', '!=', 'cancelled')->first(['id', 'number']),
            ],
            'approval' => $this->approval('sourcing', $event),
            'documents' => $this->documents('sourcing', $event),
            'suppliers' => Supplier::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name]),
            'currencies' => array_column(app(EntryCurrencies::class)->forPage()['currencies'], 'code'),
            'can' => [
                'manage' => Gate::allows('procurement.sourcing.manage'),
                'evaluate' => Gate::allows('procurement.evaluate'),
                'order' => Gate::allows('procurement.orders.manage'),
            ],
        ]);
    }

    public function update(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'quotes_due_at' => ['required', 'date'],
            'required_by' => ['nullable', 'date'],
            'sealed' => ['boolean'],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:5000'],
        ]);
        Sourcing::update($event, $data, $this->user());

        return back()->with('success', 'Saved.');
    }

    public function invite(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate(['supplier_ids' => ['required', 'array', 'min:1'], 'supplier_ids.*' => ['uuid']]);
        Sourcing::invite($event, $data['supplier_ids'], $this->user());

        return back()->with('success', 'Suppliers invited.');
    }

    public function uninvite(SourcingEvent $event, Supplier $supplier): RedirectResponse
    {
        Sourcing::uninvite($event, $supplier, $this->user());

        return back()->with('success', 'Invitation withdrawn.');
    }

    public function criteria(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate([
            'criteria' => ['present', 'array', 'max:30'],
            'criteria.*.name' => ['required', 'string', 'max:255'],
            'criteria.*.kind' => ['required', Rule::in(['pass_fail', 'score'])],
            'criteria.*.mandatory' => ['boolean'],
            'criteria.*.max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        Sourcing::setCriteria($event, $data['criteria'], $this->user());

        return back()->with('success', 'Evaluation criteria saved.');
    }

    public function issue(SourcingEvent $event): RedirectResponse
    {
        Sourcing::issue($event, $this->user());

        return back()->with('success', $event->fresh()?->status === 'issued' ? 'Issued to the invited suppliers.' : 'Submitted for approval.');
    }

    public function decide(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:2000']]);
        Sourcing::decide($event, $data['decision'], $data['note'] ?? null, $this->user());

        return back()->with('success', 'Decision recorded.');
    }

    public function storeQuotation(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'quoted_on' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'discount' => ['nullable'],
            'tax' => ['nullable'],
            'delivery' => ['nullable'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sourcing_item_id' => ['required', 'uuid'],
            'lines.*.unit_price' => ['required'],
            'lines.*.compliant' => ['boolean'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        Sourcing::recordQuotation($event, $data, $this->user());

        return back()->with('success', 'Quotation recorded.');
    }

    public function withdrawQuotation(Request $request, SourcingEvent $event, Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->sourcing_event_id !== $event->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Sourcing::withdrawQuotation($quotation, $data['reason'], $this->user());

        return back()->with('success', 'Quotation withdrawn.');
    }

    public function close(SourcingEvent $event): RedirectResponse
    {
        Sourcing::close($event, $this->user());

        return back()->with('success', 'Closed: quotations are now fixed.');
    }

    public function startEvaluation(SourcingEvent $event): RedirectResponse
    {
        Sourcing::startEvaluation($event, $this->user());

        return back()->with('success', 'Evaluation opened.');
    }

    public function evaluate(Request $request, SourcingEvent $event, Quotation $quotation): RedirectResponse
    {
        abort_if($quotation->sourcing_event_id !== $event->id, 404);
        $data = $request->validate([
            'results' => ['required', 'array'],
            'results.*.passed' => ['nullable', 'boolean'],
            'results.*.score' => ['nullable', 'numeric', 'min:0'],
            'results.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        Sourcing::evaluate($quotation->load('event'), $data['results'], $this->user());

        return back()->with('success', 'Evaluation saved.');
    }

    public function recommend(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'uuid'],
            'justification' => ['required', 'string', 'max:5000'],
            'deviation_reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $quotation = Quotation::query()->where('sourcing_event_id', $event->id)->whereKey($data['quotation_id'])->firstOrFail();
        Sourcing::recommend($event, $quotation, $data['justification'], $data['deviation_reason'] ?? null, $this->user());

        return back()->with('success', 'Award recommended for approval.');
    }

    public function decideAward(Request $request, SourcingEvent $event, Award $award): RedirectResponse
    {
        abort_if($award->sourcing_event_id !== $event->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:2000']]);
        Sourcing::decideAward($award, $data['decision'], $data['note'] ?? null, $this->user());

        return back()->with('success', 'Decision recorded.');
    }

    public function withdrawAward(SourcingEvent $event, Award $award): RedirectResponse
    {
        abort_if($award->sourcing_event_id !== $event->id, 404);
        Sourcing::withdrawAward($award->load(['event', 'supplier']), $this->user());

        return back()->with('success', 'Recommendation withdrawn.');
    }

    public function cancel(Request $request, SourcingEvent $event): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Sourcing::cancel($event, $data['reason'], $this->user());

        return back()->with('success', 'Sourcing event cancelled.');
    }
}
