<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Actions\Promotions;
use App\Modules\Sales\Models\Promotion;
use App\Support\Branches;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's promotions for the industry being served: running,
 * coming up, and past ones. The till gives them by itself (Promotions).
 */
class PromotionController extends Controller
{
    public function __construct(
        private readonly Industry $industry,
        private readonly Branches $branches,
    ) {}

    public function index(): Response
    {
        $today = CarbonImmutable::today();
        $names = $this->branches->names();

        return Inertia::render($this->industry->page('promotions/index'), [
            'promotions' => Promotion::query()
                ->where('application', $this->industry->code())
                ->with(['item:id,name', 'creator:id,name'])
                ->orderByDesc('starts_on')
                ->limit(200)
                ->get()
                ->map(fn (Promotion $promotion) => [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'percentOff' => $promotion->percent_off,
                    'on' => $promotion->item?->name ?? "Everything in {$promotion->category}",
                    'branch' => $promotion->branch_id === null ? 'Every branch' : ($names[$promotion->branch_id] ?? 'A closed branch'),
                    'startsOn' => $promotion->starts_on->toDateString(),
                    'endsOn' => $promotion->ends_on?->toDateString(),
                    'status' => match (true) {
                        ! $promotion->active, $promotion->ends_on?->lessThan($today) === true => 'ended',
                        $promotion->starts_on->greaterThan($today) => 'upcoming',
                        default => 'running',
                    },
                    'createdBy' => $promotion->creator->name,
                ]),
            'items' => Item::query()
                ->where('active', true)
                ->ofKind($this->industry->itemKinds())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Item $item) => ['id' => $item->id, 'label' => $item->name]),
            'categories' => Item::query()
                ->where('active', true)
                ->ofKind($this->industry->itemKinds())
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            'branches' => array_values(array_map(
                fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name],
                $this->branches->allActive(),
            )),
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request, Promotions $promotions): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'percent_off' => ['required', 'integer', 'min:1', 'max:100'],
            'item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'category' => ['nullable', 'string', 'max:64'],
            'branch_id' => ['nullable', 'uuid', Rule::in(array_map(fn (Branch $branch) => $branch->id, $this->branches->allActive()))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);

        $promotion = $promotions->create(
            by: $this->user($request),
            name: trim($validated['name']),
            percentOff: (int) $validated['percent_off'],
            item: ($validated['item_id'] ?? null) === null ? null : Item::query()->findOrFail($validated['item_id']),
            category: ($validated['category'] ?? null) === null ? null : trim($validated['category']),
            branchId: $validated['branch_id'] ?? null,
            startsOn: CarbonImmutable::parse($validated['starts_on']),
            endsOn: ($validated['ends_on'] ?? null) === null ? null : CarbonImmutable::parse($validated['ends_on']),
        );

        return back()->with('success', "{$promotion->name}: {$promotion->percent_off}% off from {$promotion->starts_on->toFormattedDateString()}.");
    }

    public function end(Promotion $promotion, Promotions $promotions): RedirectResponse
    {
        abort_unless($promotion->application === $this->industry->code(), 404);
        $promotions->end($promotion);

        return back()->with('success', "{$promotion->name} ended.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
