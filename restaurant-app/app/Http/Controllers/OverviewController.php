<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\BranchSetting;
use App\Models\CashHandover;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\TillShift;
use App\Support\Branches;
use App\Support\BranchFigures;
use App\Support\Money;
use App\Support\XlsxWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\Branch;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every branch together, for those who oversee them all (Global Admin and
 * the owner): sales, orders, payments, kitchens, stock and what needs
 * attention, then each branch side by side with a way into it.
 *
 * Only the branches open to the member, in their own organization: the
 * tenant scope on every model keeps other workspaces out, and the branch
 * list comes from the platform, never from the browser. A branch filter
 * must be one of those.
 *
 * Money, one definition throughout, the day report's: sales are bills paid
 * in the period at what was charged (after discounts), refunded or not;
 * refunds are bills voided in the period; net sales are sales less
 * refunds. Bill counts and averages are of bills still paid. Only the workspace currency is
 * added up; bills in any other currency are counted apart, never mixed in.
 * Days run in the application's time zone, as everywhere else.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly BranchFigures $figures) {}

    /** The longest period one page adds up (and draws day by day). */
    private const MAX_DAYS = 92;

    /** The most orders one Excel download lists. */
    private const MAX_EXPORT_ORDERS = 10000;

    public function __invoke(Request $request, Branches $branches): Response|StreamedResponse
    {
        abort_unless($branches->overview(), 403, 'Only those who oversee every branch see them together.');

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:open,paid,cancelled'],
            'export' => ['nullable', 'in:xlsx'],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : CarbonImmutable::today()->endOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : $to->startOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }

        $open = $branches->available();
        $chosen = $validated['branch'] ?? null;
        if ($chosen !== null && ! in_array($chosen, array_map(fn (Branch $branch) => $branch->id, $open), true)) {
            throw ValidationException::withMessages(['branch' => 'Choose one of your branches.']);
        }
        $shown = array_values(array_filter($open, fn (Branch $branch) => $chosen === null || $branch->id === $chosen));
        $ids = array_map(fn (Branch $branch) => $branch->id, $shown);
        $currency = Money::currency();

        // The same period just before, for comparison.
        $length = (int) round($from->diffInDays($to->addSecond()));
        $before = [$from->subDays($length), $from->subSecond()];

        $paid = fn (array $between) => Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->whereBetween('paid_at', $between);
        $refunded = fn (array $between) => Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->where('status', Bill::VOIDED)->whereBetween('voided_at', $between);
        $period = [$from, $to];

        $sales = $paid($period)->toBase()
            ->selectRaw("coalesce(sum(case when status = 'paid' then 1 else 0 end), 0) as bills, count(distinct case when status = 'paid' then order_id end) as orders, coalesce(sum(case when status = 'paid' then total_minor else 0 end), 0) as kept, coalesce(sum(subtotal_minor), 0) as gross, coalesce(sum(discount_minor), 0) as discounts, coalesce(sum(total_minor), 0) as total")
            ->first();
        $refunds = $refunded($period)->toBase()->selectRaw('count(*) as bills, count(distinct order_id) as orders, coalesce(sum(total_minor), 0) as total')->first();
        $total = (int) ($sales->total ?? 0);
        $refundedMinor = (int) ($refunds->total ?? 0);
        $orders = (int) ($sales->orders ?? 0);
        $previous = (int) $paid($before)->sum('total_minor') - (int) $refunded($before)->sum('total_minor');

        $byMethod = $paid($period)->toBase()
            ->selectRaw('payment_method, count(*) as bills, coalesce(sum(total_minor), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn (object $row) => [
                'method' => (string) $row->payment_method,
                'name' => PaymentMethod::tryFrom((string) $row->payment_method)?->label() ?? (string) $row->payment_method,
                'count' => (int) $row->bills,
                'totalMinor' => (int) $row->total,
            ])
            ->sortByDesc('totalMinor')
            ->values();
        $cash = (int) $byMethod->where('method', PaymentMethod::Cash->value)->sum('totalMinor');

        $orderCounts = Order::query()->whereIn('branch_id', $ids)->whereBetween('opened_at', $period)
            ->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $trend = $this->trend($paid($period), $refunded($period), $from, $to);

        $props = [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $chosen,
                'status' => $validated['status'] ?? null,
            ],
            'currency' => $currency,
            'branches' => array_map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name, 'city' => $branch->city], $open),
            'totals' => [
                'grossMinor' => (int) ($sales->gross ?? 0),
                'discountMinor' => (int) ($sales->discounts ?? 0),
                'salesMinor' => $total,
                'refundedMinor' => $refundedMinor,
                'netMinor' => $total - $refundedMinor,
                'previousNetMinor' => $previous,
                'cashMinor' => $cash,
                'nonCashMinor' => $total - $cash,
                'bills' => (int) ($sales->bills ?? 0),
                'averageOrderMinor' => $orders === 0 ? 0 : intdiv((int) ($sales->kept ?? 0), $orders),
                'orders' => [
                    'total' => (int) $orderCounts->sum(),
                    'completed' => (int) ($orderCounts[Order::PAID] ?? 0),
                    'pending' => (int) ($orderCounts[Order::OPEN] ?? 0),
                    'cancelled' => (int) ($orderCounts[Order::CANCELLED] ?? 0),
                    'refunded' => (int) ($refunds->orders ?? 0),
                ],
            ],
            // Bills in another currency than the workspace's: counted, not added.
            'otherCurrencies' => Bill::query()->whereIn('branch_id', $ids)->where('currency', '!=', $currency)->whereBetween('paid_at', $period)
                ->toBase()->selectRaw('currency, count(*) as n')->groupBy('currency')->pluck('n', 'currency'),
            'byMethod' => $byMethod->all(),
            'trend' => $trend['days'],
            'hourly' => $trend['hours'],
            'kitchen' => $this->kitchen($ids),
            'performance' => $this->figures->performance($shown, $period, $currency),
            'alerts' => $this->alerts($shown),
        ];

        if (($validated['export'] ?? null) === 'xlsx') {
            return $this->export($props, $ids, $period, $validated['status'] ?? null, $shown);
        }

        return Inertia::render('overview', [
            ...$props,
            'orders' => $this->orders($ids, $period, $validated['status'] ?? null, $shown),
        ]);
    }

    /**
     * The page as an Excel workbook: the summary, each chart's figures and
     * every order in the period (not one page of them), a sheet each.
     *
     * @param  array<string, mixed>  $props
     * @param  list<string>  $ids
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period
     * @param  list<Branch>  $branches
     */
    private function export(array $props, array $ids, array $period, ?string $status, array $branches): StreamedResponse
    {
        $totals = $props['totals'];
        $filters = $props['filters'];
        $branchName = $filters['branch'] === null ? 'All branches' : ($branches[0]->name ?? '');

        $summary = [
            ['label' => 'Period', 'value' => $filters['from'] === $filters['to'] ? $filters['from'] : "{$filters['from']} to {$filters['to']}"],
            ['label' => 'Branch', 'value' => $branchName],
            ['label' => 'Currency', 'value' => $props['currency']],
            ['label' => 'Net sales', 'valueMinor' => $totals['netMinor']],
            ['label' => 'Net sales, period before', 'valueMinor' => $totals['previousNetMinor']],
            ['label' => 'Sales before discounts', 'valueMinor' => $totals['grossMinor']],
            ['label' => 'Discounts given', 'valueMinor' => $totals['discountMinor']],
            ['label' => 'Refunds', 'valueMinor' => $totals['refundedMinor']],
            ['label' => 'Taken in cash', 'valueMinor' => $totals['cashMinor']],
            ['label' => 'Taken by other means', 'valueMinor' => $totals['nonCashMinor']],
            ['label' => 'Average order', 'valueMinor' => $totals['averageOrderMinor']],
            ['label' => 'Bills paid', 'value' => $totals['bills']],
            ['label' => 'Orders', 'value' => $totals['orders']['total']],
            ['label' => 'Orders paid', 'value' => $totals['orders']['completed']],
            ['label' => 'Orders open', 'value' => $totals['orders']['pending']],
            ['label' => 'Orders cancelled', 'value' => $totals['orders']['cancelled']],
            ['label' => 'Orders refunded', 'value' => $totals['orders']['refunded']],
        ];

        $statuses = [Order::OPEN => 'Open', Order::PAID => 'Paid', Order::CANCELLED => 'Cancelled'];
        $names = collect($branches)->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->name]);
        $orders = Order::query()
            ->whereIn('branch_id', $ids)
            ->whereBetween('opened_at', $period)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['table:id,name', 'opener:id,name'])
            ->withSum(['bills as kept_minor' => fn ($query) => $query->where('status', Bill::PAID)], 'total_minor')
            ->latest('opened_at')
            ->limit(self::MAX_EXPORT_ORDERS)
            ->get()
            ->map(fn (Order $order) => [
                'number' => $order->number,
                'branch' => $names[$order->branch_id] ?? '',
                'where' => $order->table ? "Table {$order->table->name}" : ($order->customer_name ?? 'Takeaway'),
                'status' => $statuses[$order->status] ?? $order->status,
                'opened' => $order->opened_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
                'openedBy' => $order->opener->name,
                'paidMinor' => (int) ($order->getAttribute('kept_minor') ?? 0),
            ]);

        $range = $filters['from'] === $filters['to'] ? $filters['from'] : "{$filters['from']}-to-{$filters['to']}";

        return XlsxWorkbook::download("all-branches-{$range}", [
            ['name' => 'Summary', 'columns' => ['Figure' => 'label', 'Amount' => 'valueMinor', 'Value' => 'value'], 'rows' => $summary],
            ['name' => 'Branches', 'columns' => [
                'Branch' => 'name', 'City' => 'city', 'Net sales' => 'netMinor', 'Orders' => 'orders', 'Average order' => 'averageOrderMinor',
                'Open orders now' => 'openOrders', 'In kitchen now' => 'pendingTickets', 'Low stock items' => 'lowStock', 'Till' => 'till',
            ], 'rows' => array_map(fn (array $row) => [...$row, 'till' => $row['status'] === 'trading' ? 'Open' : 'None open'], $props['performance'])],
            ['name' => 'Sales by day', 'columns' => ['Day' => 'day', 'Net sales' => 'netMinor'], 'rows' => $props['trend']],
            ['name' => 'Sales by hour', 'columns' => ['Hour' => 'label', 'Net sales' => 'netMinor', 'Bills' => 'bills'],
                'rows' => array_map(fn (array $hour) => [...$hour, 'label' => sprintf('%02d:00', $hour['hour'])], $props['hourly'])],
            ['name' => 'Payment methods', 'columns' => ['Method' => 'name', 'Bills' => 'count', 'Amount' => 'totalMinor'], 'rows' => $props['byMethod']],
            ['name' => 'Orders', 'columns' => [
                'Order' => 'number', 'Branch' => 'branch', 'Where' => 'where', 'Status' => 'status', 'Opened' => 'opened', 'Opened by' => 'openedBy', 'Paid' => 'paidMinor',
            ], 'rows' => $orders],
            ['name' => 'Needs attention', 'columns' => ['Branch' => 'branch', 'Kind' => 'kind', 'What' => 'message'], 'rows' => $props['alerts']],
        ], $props['currency']);
    }

    /**
     * Net sales day by day, and hour of the day by hour of the day (every
     * day of the period added together). Only two columns are read, and
     * the days and hours are the application's, not the database server's.
     *
     * @param  Builder<Bill>  $paid
     * @param  Builder<Bill>  $refunded
     * @return array{days: list<array{day: string, netMinor: int}>, hours: list<array{hour: int, netMinor: int, bills: int}>}
     */
    private function trend(Builder $paid, Builder $refunded, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $days[$day->toDateString()] = 0;
        }
        $hourNet = array_fill(0, 24, 0);
        $hourBills = array_fill(0, 24, 0);
        $zone = config('app.timezone');

        foreach ($paid->toBase()->select(['paid_at', 'total_minor'])->cursor() as $row) {
            $at = CarbonImmutable::parse($row->paid_at)->setTimezone($zone);
            if (isset($days[$at->toDateString()])) {
                $days[$at->toDateString()] += (int) $row->total_minor;
                $hourNet[$at->hour] += (int) $row->total_minor;
                $hourBills[$at->hour]++;
            }
        }
        foreach ($refunded->toBase()->select(['voided_at', 'total_minor'])->cursor() as $row) {
            $at = CarbonImmutable::parse($row->voided_at)->setTimezone($zone);
            if (isset($days[$at->toDateString()])) {
                $days[$at->toDateString()] -= (int) $row->total_minor;
                $hourNet[$at->hour] -= (int) $row->total_minor;
            }
        }

        return [
            'days' => array_map(fn (string $day, int $net) => ['day' => $day, 'netMinor' => $net], array_keys($days), array_values($days)),
            'hours' => array_map(fn (int $hour) => ['hour' => $hour, 'netMinor' => $hourNet[$hour], 'bills' => $hourBills[$hour]], range(0, 23)),
        ];
    }

    /**
     * Tickets in the kitchens now, whatever the period.
     *
     * @param  list<string>  $ids
     * @return array{preparing: int, ready: int, slow: int, oldestMinutes: int|null}
     */
    private function kitchen(array $ids): array
    {
        $waiting = KitchenTicket::query()->whereIn('branch_id', $ids)->whereIn('status', [KitchenTicket::PREPARING, KitchenTicket::READY]);
        $counts = (clone $waiting)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $oldest = (clone $waiting)->where('status', KitchenTicket::PREPARING)->min('sent_at');

        return [
            'preparing' => (int) ($counts[KitchenTicket::PREPARING] ?? 0),
            'ready' => (int) ($counts[KitchenTicket::READY] ?? 0),
            'slow' => (clone $waiting)->where('status', KitchenTicket::PREPARING)->where('sent_at', '<', now()->subMinutes(BranchSetting::SLOW_TICKET_MINUTES))->count(),
            'oldestMinutes' => $oldest === null ? null : (int) CarbonImmutable::parse($oldest)->diffInMinutes(now()),
        ];
    }

    /**
     * What needs someone's attention now.
     *
     * @param  list<Branch>  $branches
     * @return list<array{branchId: string, branch: string, kind: string, message: string}>
     */
    private function alerts(array $branches): array
    {
        $names = collect($branches)->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->name]);
        $ids = array_map(fn (Branch $branch) => $branch->id, $branches);
        $alerts = [];

        foreach (TillShift::query()->whereIn('branch_id', $ids)->where('status', TillShift::OPEN)->where('opened_at', '<', today())->with('opener:id,name')->get() as $till) {
            $alerts[] = ['branchId' => $till->branch_id, 'branch' => $names[$till->branch_id], 'kind' => 'till', 'message' => "Till {$till->number} of {$till->opener->name} has been open since {$till->opened_at->toDateString()}."];
        }

        // Each branch's own idea of late (its settings).
        $settings = BranchSetting::query()->whereIn('branch_id', $ids)->get()->keyBy('branch_id');
        $cooking = KitchenTicket::query()->whereIn('branch_id', $ids)->where('status', KitchenTicket::PREPARING)
            ->where('sent_at', '<', now()->subMinutes(BranchSetting::SLOW_TICKET_MINUTES_MIN))
            ->toBase()->get(['branch_id', 'sent_at'])->groupBy('branch_id');
        foreach ($cooking as $branchId => $tickets) {
            $minutes = ($settings->get($branchId) ?? new BranchSetting)->slowTicketMinutes();
            $count = $tickets->filter(fn (object $ticket) => CarbonImmutable::parse($ticket->sent_at)->lt(now()->subMinutes($minutes)))->count();
            if ($count > 0) {
                $alerts[] = ['branchId' => (string) $branchId, 'branch' => $names[$branchId], 'kind' => 'kitchen', 'message' => "{$count} kitchen ticket(s) waiting more than {$minutes} minutes."];
            }
        }

        // Cash handed over yesterday or before and still not received.
        $waiting = CashHandover::query()->whereIn('branch_id', $ids)->where('status', CashHandover::PENDING)->where('handed_at', '<', today())
            ->toBase()->selectRaw('branch_id, count(*) as n, sum(amount_minor) as total')->groupBy('branch_id')->get();
        foreach ($waiting as $row) {
            $alerts[] = ['branchId' => (string) $row->branch_id, 'branch' => $names[$row->branch_id], 'kind' => 'handover', 'message' => "{$row->n} cash handover(s) of ".Money::format((int) $row->total).' not yet received.'];
        }

        foreach ($this->figures->lowStock($ids) as $line) {
            $alerts[] = [
                'branchId' => $line['branchId'],
                'branch' => $names[$line['branchId']],
                'kind' => $line['quantity'] < 0 ? 'count' : 'stock',
                'message' => $line['quantity'] < 0
                    ? "{$line['item']}: {$line['quantity']} in stock. Count it."
                    : "{$line['item']}: {$line['quantity']} left (reorder at {$line['reorderLevel']}).",
            ];
        }

        return $alerts;
    }

    /**
     * Orders opened in the period, newest first, a page at a time.
     *
     * @param  list<string>  $ids
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period
     * @param  list<Branch>  $branches
     * @return array<string, mixed>
     */
    private function orders(array $ids, array $period, ?string $status, array $branches): array
    {
        $names = collect($branches)->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->name]);
        $page = Order::query()
            ->whereIn('branch_id', $ids)
            ->whereBetween('opened_at', $period)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['table:id,name', 'opener:id,name'])
            ->withSum(['bills as kept_minor' => fn ($query) => $query->where('status', Bill::PAID)], 'total_minor')
            ->latest('opened_at')
            ->paginate(20)
            ->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'branchId' => $order->branch_id,
                'branch' => $names[$order->branch_id] ?? '',
                'where' => $order->table ? "Table {$order->table->name}" : ($order->customer_name ?? 'Takeaway'),
                'status' => $order->status,
                'openedAt' => $order->opened_at->toIso8601String(),
                'openedBy' => $order->opener->name,
                'paidMinor' => (int) ($order->getAttribute('kept_minor') ?? 0),
            ])->all(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
            'prev' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
        ];
    }
}
