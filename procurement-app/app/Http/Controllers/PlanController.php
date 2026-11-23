<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceMember;
use App\Procurement\Departments;
use App\Procurement\Models\Plan;
use App\Procurement\Models\PlanItem;
use App\Procurement\Models\Requisition;
use App\Procurement\Money;
use App\Procurement\Plans;
use App\Procurement\Sourcing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('plans/index', [
            'plans' => Plan::query()->withCount('items')->withSum('items', 'estimated_base_minor')->latest()->get()
                ->map(fn (Plan $p) => [
                    'id' => $p->id, 'number' => $p->number, 'title' => $p->title, 'fiscalYear' => $p->fiscal_year,
                    'department' => $p->department_name, 'status' => $p->status, 'revision' => $p->revision,
                    'items' => $p->getAttribute('items_count'),
                    'total' => self::major((int) $p->getAttribute('items_sum_estimated_base_minor'), Money::base()),
                ]),
            'currency' => Money::base(),
            'departments' => Departments::options(),
            'canManage' => Gate::allows('procurement.plans.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = Plans::save(null, $this->validated($request), $this->user());

        return to_route('plans.show', $plan)->with('success', 'Plan created. Add the planned purchases.');
    }

    public function show(Plan $plan): Response
    {
        $plan->load('items.responsible:id,name');
        $used = Requisition::query()->whereIn('plan_item_id', $plan->items->pluck('id'))->whereIn('status', ['pending_approval', 'approved', 'closed'])
            ->selectRaw('plan_item_id, sum(total_base_minor) as used')->groupBy('plan_item_id')->pluck('used', 'plan_item_id');
        $base = Money::base();

        return Inertia::render('plans/show', [
            'plan' => [
                'id' => $plan->id, 'number' => $plan->number, 'title' => $plan->title, 'fiscalYear' => $plan->fiscal_year,
                'departmentId' => $plan->department_id, 'department' => $plan->department_name,
                'status' => $plan->status, 'revision' => $plan->revision, 'notes' => $plan->getAttribute('notes'),
                'total' => self::major((int) $plan->items->sum('estimated_base_minor'), $base),
                'items' => $plan->items->map(fn (PlanItem $i) => [
                    'id' => $i->id, 'description' => $i->description, 'category' => $i->getAttribute('category'),
                    'method' => $i->method, 'quantity' => $i->quantity, 'unit' => $i->getAttribute('unit'),
                    'estimated' => self::major($i->estimated_base_minor, $base),
                    'used' => self::major((int) ($used[$i->id] ?? 0), $base),
                    'expectedOn' => self::date($i->getAttribute('expected_on')),
                    'fundingReference' => $i->getAttribute('funding_reference'), 'projectReference' => $i->getAttribute('project_reference'),
                    'responsibleId' => $i->getAttribute('responsible_user_id'), 'responsible' => $i->responsible?->name,
                ]),
            ],
            'currency' => $base,
            'approval' => $this->approval('plan', $plan),
            'documents' => $this->documents('plan', $plan),
            'methods' => self::methodOptions(),
            'members' => WorkspaceMember::options(),
            'departments' => Departments::options(),
            'canManage' => Gate::allows('procurement.plans.manage'),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        Plans::save($plan, $this->validated($request), $this->user());

        return back()->with('success', 'Plan saved.');
    }

    public function storeItem(Request $request, Plan $plan, ?PlanItem $item = null): RedirectResponse
    {
        abort_if($item !== null && $item->plan_id !== $plan->id, 404);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'method' => ['required', Rule::in(array_keys(Sourcing::LABELS))],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:32'],
            'estimated_amount' => ['required'],
            'expected_on' => ['nullable', 'date'],
            'funding_reference' => ['nullable', 'string', 'max:120'],
            'project_reference' => ['nullable', 'string', 'max:120'],
            'responsible_user_id' => ['nullable', WorkspaceMember::rule()],
        ]);
        Plans::saveItem($plan, $item, $data, $this->user());

        return back()->with('success', 'Planned purchase saved.');
    }

    public function destroyItem(Plan $plan, PlanItem $item): RedirectResponse
    {
        abort_if($item->plan_id !== $plan->id, 404);
        Plans::removeItem($plan, $item, $this->user());

        return back()->with('success', 'Planned purchase removed.');
    }

    public function submit(Plan $plan): RedirectResponse
    {
        Plans::submit($plan, $this->user());

        return back()->with('success', 'Plan submitted.');
    }

    public function decide(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:2000']]);
        Plans::decide($plan, $data['decision'], $data['note'] ?? null, $this->user());

        return back()->with('success', 'Decision recorded.');
    }

    public function revise(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Plans::revise($plan, $data['reason'], $this->user());

        return back()->with('success', 'Plan back to draft for revision.');
    }

    public function close(Plan $plan): RedirectResponse
    {
        Plans::close($plan, $this->user());

        return back()->with('success', 'Plan closed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'fiscal_year' => ['required', 'string', 'max:16'],
            'department_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
