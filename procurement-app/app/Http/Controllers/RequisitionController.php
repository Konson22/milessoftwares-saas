<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Procurement\Access;
use App\Procurement\Departments;
use App\Procurement\Models\PlanItem;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\RequisitionLine;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\Supplier;
use App\Procurement\Money;
use App\Procurement\Requisitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Money\EntryCurrencies;

/**
 * Purchase requisitions. Requesters reach their own; approvers and those
 * who see all procurement reach every one (App\Procurement\Access).
 */
class RequisitionController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $mine = $request->boolean('mine');

        return Inertia::render('requisitions/index', [
            'status' => $status,
            'mine' => $mine,
            'requisitions' => Access::requisitions()->with('requester:id,name')
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($mine, fn ($q) => $q->where('requester_id', $this->user()->id))
                ->latest()->limit(200)->get()
                ->map(fn (Requisition $r) => [
                    'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'status' => $r->status,
                    'requester' => $r->requester->name, 'department' => $r->department_name,
                    'total' => self::major($r->total_minor, $r->currency), 'currency' => $r->currency,
                    'neededBy' => self::date($r->getAttribute('needed_by')), 'date' => self::date($r->created_at),
                ]),
            'statuses' => Requisition::STATUSES,
            'canCreate' => Gate::allows('procurement.requisitions.create'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('requisitions/form', $this->formProps(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Saved and submitted together, or not at all.
        $requisition = DB::transaction(function () use ($data, $request) {
            $requisition = Requisitions::save(null, $data, $this->user());
            if ($request->boolean('submit')) {
                Requisitions::submit($requisition, $this->user());
            }

            return $requisition;
        });

        return to_route('requisitions.show', $requisition)->with('success', $request->boolean('submit') ? 'Requisition submitted.' : 'Draft saved.');
    }

    public function edit(string $requisition): Response
    {
        $model = Access::requisition($requisition);
        abort_unless($model->editable() && $model->requester_id === $this->user()->id, 403);

        return Inertia::render('requisitions/form', $this->formProps($model));
    }

    public function update(Request $request, string $requisition): RedirectResponse
    {
        $model = Access::requisition($requisition);
        $data = $this->validated($request);
        DB::transaction(function () use ($model, $data, $request) {
            Requisitions::save($model, $data, $this->user());
            if ($request->boolean('submit')) {
                Requisitions::submit($model, $this->user());
            }
        });

        return to_route('requisitions.show', $model)->with('success', $request->boolean('submit') ? 'Requisition submitted.' : 'Draft saved.');
    }

    public function show(string $requisition): Response
    {
        $model = Access::requisition($requisition)->load(['lines', 'requester:id,name', 'planItem.plan']);
        $mine = $model->requester_id === $this->user()->id;
        $seesAll = Access::seesAll();

        return Inertia::render('requisitions/show', [
            'requisition' => [
                'id' => $model->id,
                'number' => $model->number,
                'title' => $model->title,
                'justification' => $model->justification,
                'status' => $model->status,
                'statusReason' => $model->getAttribute('status_reason'),
                'revision' => $model->revision,
                'requester' => $model->requester->name,
                'department' => $model->department_name,
                'neededBy' => self::date($model->getAttribute('needed_by')),
                'deliveryLocation' => $model->getAttribute('delivery_location'),
                'currency' => $model->currency,
                'total' => self::major($model->total_minor, $model->currency),
                'totalBase' => $model->currency === Money::base() ? null : self::major($model->total_base_minor, Money::base()),
                'baseCurrency' => Money::base(),
                'budgetReference' => $model->getAttribute('budget_reference'),
                'projectReference' => $model->getAttribute('project_reference'),
                'fundingReference' => $model->getAttribute('funding_reference'),
                'planItem' => $model->planItem === null ? null : $model->planItem->plan->number.' · '.$model->planItem->description,
                'submittedAt' => self::time($model->getAttribute('submitted_at')),
                'createdAt' => self::time($model->created_at),
                'lines' => $model->lines->map(fn (RequisitionLine $l) => [
                    'id' => $l->id, 'kind' => $l->kind, 'description' => $l->description, 'specification' => $l->specification,
                    'quantity' => $l->quantity, 'unit' => $l->unit,
                    'unitPrice' => self::major($l->unit_price_minor, $model->currency), 'amount' => self::major($l->amount_minor, $model->currency),
                ]),
            ],
            // How the buying is going: requesters follow it through to delivery.
            'progress' => [
                'sourcing' => SourcingEvent::query()->where('requisition_id', $model->id)->latest()->get()
                    ->map(fn (SourcingEvent $e) => ['id' => $e->id, 'number' => $e->number, 'status' => $e->status, 'link' => $seesAll]),
                'orders' => PurchaseOrder::query()->with('supplier:id,name')->where('requisition_id', $model->id)->latest()->get()
                    ->map(fn (PurchaseOrder $o) => [
                        'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'supplier' => $o->supplier->name,
                        'deliveryDate' => self::date($o->getAttribute('delivery_date')), 'link' => $seesAll,
                    ]),
            ],
            'approval' => $this->approval('requisition', $model),
            'documents' => $this->documents('requisition', $model),
            'can' => [
                'edit' => $mine && $model->editable(),
                'submit' => $mine && $model->editable(),
                'cancel' => ($mine || Gate::allows('procurement.sourcing.manage')) && in_array($model->status, ['draft', 'returned', 'pending_approval', 'approved'], true),
                'amend' => $mine && $model->status === 'approved',
                'source' => Gate::allows('procurement.sourcing.manage') && $model->status === 'approved',
                'order' => Gate::allows('procurement.orders.manage') && $model->status === 'approved',
            ],
            'methods' => self::methodOptions(true),
            'suppliers' => Gate::allows('procurement.orders.manage') ? Supplier::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name]) : [],
        ]);
    }

    public function submit(string $requisition): RedirectResponse
    {
        Requisitions::submit(Access::requisition($requisition), $this->user());

        return back()->with('success', 'Requisition submitted.');
    }

    public function decide(Request $request, string $requisition): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'return'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $outcome = Requisitions::decide(Access::requisition($requisition), $data['decision'], $data['note'] ?? null, $this->user());

        return back()->with('success', match ($outcome) {
            'complete' => 'Requisition approved.',
            'pending' => 'Approved; the next step is waiting.',
            'rejected' => 'Requisition rejected.',
            default => 'Requisition returned to the requester.',
        });
    }

    public function cancel(Request $request, string $requisition): RedirectResponse
    {
        $model = Access::requisition($requisition);
        abort_unless($model->requester_id === $this->user()->id || Gate::allows('procurement.sourcing.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Requisitions::cancel($model, $data['reason'], $this->user());

        return back()->with('success', 'Requisition cancelled.');
    }

    public function amend(Request $request, string $requisition): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $model = Access::requisition($requisition);
        Requisitions::amend($model, $data['reason'], $this->user());

        return to_route('requisitions.edit', $model)->with('success', 'Back to draft as revision '.($model->revision + 1).'. Change it and submit again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(?Requisition $model): array
    {
        $model?->load('lines');

        return [
            'requisition' => $model === null ? null : [
                'id' => $model->id,
                'number' => $model->number,
                'title' => $model->title,
                'justification' => $model->justification,
                'department_id' => $model->department_id ?? '',
                'plan_item_id' => $model->plan_item_id ?? '',
                'needed_by' => self::date($model->getAttribute('needed_by')) ?? '',
                'delivery_location' => $model->getAttribute('delivery_location') ?? '',
                'currency' => $model->currency,
                'budget_reference' => $model->getAttribute('budget_reference') ?? '',
                'project_reference' => $model->getAttribute('project_reference') ?? '',
                'funding_reference' => $model->getAttribute('funding_reference') ?? '',
                'status' => $model->status,
                'statusReason' => $model->getAttribute('status_reason'),
                'lines' => $model->lines->map(fn (RequisitionLine $l) => [
                    'kind' => $l->kind, 'description' => $l->description, 'specification' => $l->specification ?? '',
                    'quantity' => rtrim(rtrim($l->quantity, '0'), '.'), 'unit' => $l->unit ?? '',
                    'unit_price' => Money::major($l->unit_price_minor, $model->currency),
                ])->all(),
            ],
            'departments' => Departments::options(),
            'planItems' => PlanItem::query()->whereHas('plan', fn ($q) => $q->where('status', 'approved'))->with('plan:id,number')->get()
                ->map(fn (PlanItem $i) => ['value' => $i->id, 'label' => $i->plan->number.' · '.$i->description]),
            'requirePlan' => Workspace::settings()->require_plan,
            'currencies' => array_column(app(EntryCurrencies::class)->forPage()['currencies'], 'code'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'justification' => ['required', 'string', 'max:5000'],
            'department_id' => ['nullable', 'string', 'max:64'],
            'plan_item_id' => ['nullable', 'uuid'],
            'needed_by' => ['nullable', 'date'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'budget_reference' => ['nullable', 'string', 'max:120'],
            'project_reference' => ['nullable', 'string', 'max:120'],
            'funding_reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.kind' => ['required', Rule::in(['goods', 'service'])],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.specification' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['required'],
        ], [], ['lines.*.quantity' => 'quantity', 'lines.*.description' => 'description', 'lines.*.unit_price' => 'unit price']);
    }
}
