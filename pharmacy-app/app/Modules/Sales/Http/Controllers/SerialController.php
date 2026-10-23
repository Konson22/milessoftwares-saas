<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Actions\Serials;
use App\Modules\Sales\Models\ItemSerial;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Serial numbers of this industry's items that track them: looked up (for
 * a warranty, a return), and recorded as units arrive (Serials).
 */
class SerialController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim($request->string('serial')->toString());
        $items = Item::query()
            ->where('tracks_serials', true)
            ->ofKind($this->industry->itemKinds())
            ->orderBy('name')
            ->get(['id', 'name']);
        $inStock = ItemSerial::query()
            ->whereIn('item_id', $items->modelKeys())
            ->where('status', ItemSerial::IN_STOCK)
            ->groupBy('item_id')
            ->selectRaw('item_id, COUNT(*) AS serials')
            ->pluck('serials', 'item_id');

        return Inertia::render($this->industry->page('serials/index'), [
            'items' => $items->map(fn (Item $item) => [
                'id' => $item->id,
                'label' => $item->name,
                'inStock' => (int) ($inStock[$item->id] ?? 0),
            ]),
            'serials' => ItemSerial::query()
                ->whereIn('item_id', $items->modelKeys())
                ->when($search !== '', fn ($query) => $query->where('serial', 'like', '%'.addcslashes($search, '%_\\').'%'), fn ($query) => $query->latest('recorded_at'))
                ->with(['item:id,name', 'sale:id,number,customer_name,sold_at,branch_id'])
                ->limit(50)
                ->get()
                ->map(fn (ItemSerial $serial) => [
                    'id' => $serial->id,
                    'serial' => $serial->serial,
                    'item' => $serial->item->name,
                    'status' => $serial->status,
                    'recordedAt' => $serial->recorded_at->toIso8601String(),
                    'sale' => $serial->sale === null ? null : [
                        'id' => $serial->sale->id,
                        'number' => $serial->sale->number,
                        'customer' => $serial->sale->customer_name,
                        'soldAt' => $serial->sale->sold_at->toIso8601String(),
                        // Sales are opened in the branch that made them.
                        'here' => $serial->sale->branch_id === $this->branch->id(),
                    ],
                ]),
            'filters' => ['serial' => $search],
            'canRecord' => app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission('stock.adjust'), branchId: $this->branch->id()) ?? false,
        ]);
    }

    public function store(Request $request, Serials $serials): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'serials' => ['required', 'string', 'max:20000'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $item = Item::query()->findOrFail($validated['item_id']);
        $recorded = $serials->record($item, preg_split('/[\r\n,]+/', $validated['serials']) ?: [], $user);

        return back()->with('success', "{$recorded} serial ".($recorded === 1 ? 'number' : 'numbers')." of {$item->name} recorded.");
    }
}
