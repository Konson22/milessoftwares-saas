<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders;
use App\Actions\Tills;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Http\Controllers\Controller;
use App\Models\AppSubmission;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the mobile app did while it could not reach the restaurant, sent
 * when it can: opening an order, adding and removing lines, sending them to
 * the kitchen, taking payment. Each comes with its submission id; one sent
 * again (its answer was lost on the way back) gets the first answer and is
 * never applied twice. Orders and lines keep the ids the phone gave them,
 * so what came later on the phone finds them; a table already served gives
 * its own order instead, and the answer says which.
 *
 * The same rules as the web (Orders): a refusal is a 422 with the reason,
 * which the phone shows for a person to deal with. Also the kit: what the
 * phone needs to open an order offline (the menu, the payment methods,
 * whether this member's till is open).
 */
class OutboxController extends Controller
{
    use WorksInBranch;

    private const KINDS = ['order.open', 'order.add', 'order.remove', 'order.send', 'order.pay'];

    public function __construct(private readonly Orders $orders) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'data' => ['present', 'array'],
            'queued_at' => ['nullable', 'date'],
        ]);
        $id = strtolower($validated['id']);

        if (($done = $this->applied($id)) !== null) {
            return $this->answer($done);
        }

        abort_unless($this->canHere($validated['kind'] === 'order.pay' ? 'restaurant.bills.take' : 'restaurant.orders.take'), 403, 'You do not have this permission in this branch.');

        try {
            $result = DB::transaction(function () use ($request, $validated, $id) {
                $result = $this->apply($request, $validated['kind'], $validated['data']);

                AppSubmission::query()->create([
                    'id' => $id,
                    'branch_id' => $this->branchId(),
                    'user_id' => $this->member($request)->id,
                    'kind' => $validated['kind'],
                    'result' => $result,
                    'queued_at' => $validated['queued_at'] ?? null,
                    'created_at' => now(),
                ]);

                return $result;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same submission, applied by a request running alongside.
            $done = $this->applied($id) ?? throw $e;

            return $this->answer($done);
        }

        return response()->json(['result' => $result, 'replayed' => false]);
    }

    public function kit(Request $request, Tills $tills): JsonResponse
    {
        return response()->json([
            'menu' => MenuItem::query()
                ->where('active', true)
                ->orderBy('section')
                ->orderBy('name')
                ->get(['id', 'name', 'kind', 'section', 'price_minor'])
                ->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'kind' => $item->kind,
                    'section' => $item->section,
                    'priceMinor' => $item->price_minor,
                ])
                ->values(),
            'paymentMethods' => PaymentMethod::options(),
            'tillOpen' => $tills->openFor($this->member($request), $this->branchId()) !== null,
            'can' => [
                'take' => $this->canHere('restaurant.orders.take'),
                'void' => $this->canHere('restaurant.orders.void'),
                'pay' => $this->canHere('restaurant.bills.take'),
            ],
        ]);
    }

    /**
     * The submission, if it was applied already. It reads the table each
     * time: a request running alongside may just have applied it.
     *
     * @phpstan-impure
     */
    private function applied(string $id): ?AppSubmission
    {
        return AppSubmission::query()->find($id);
    }

    /**
     * A submission applied already: its first answer again.
     */
    private function answer(AppSubmission $done): JsonResponse
    {
        abort_unless($done->branch_id === $this->branchId(), 409, 'This was sent from another branch.');

        return response()->json(['result' => $done->result, 'replayed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function apply(Request $request, string $kind, array $data): array
    {
        $by = $this->member($request);

        if ($kind === 'order.open') {
            $open = validator($data, [
                'order_id' => ['required', 'uuid'],
                'service' => ['required', Rule::in([Order::DINE_IN, Order::TAKEAWAY])],
                'dining_table_id' => ['nullable', 'uuid', 'required_if:service,'.Order::DINE_IN],
                'guests' => ['nullable', 'integer', 'min:1', 'max:100'],
                'customer_name' => ['nullable', 'string', 'max:255'],
            ])->validate();

            $order = $this->orders->open(
                $by,
                $this->branchId(),
                $open['service'],
                $open['service'] === Order::DINE_IN ? $open['dining_table_id'] : null,
                isset($open['guests']) ? (int) $open['guests'] : null,
                $open['customer_name'] ?? null,
                strtolower($open['order_id']),
            );

            return ['order_id' => $order->id, 'number' => $order->number, 'joined' => $order->id !== strtolower($open['order_id'])];
        }

        $order = $this->order($data);

        return match ($kind) {
            'order.add' => $this->add($order, $by, $data),
            'order.remove' => $this->remove($order, $data),
            'order.send' => $this->send($order, $by),
            'order.pay' => $this->pay($order, $by, $data),
            default => throw ValidationException::withMessages(['kind' => 'This app is newer than the restaurant: update the restaurant.']),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function order(array $data): Order
    {
        $id = validator($data, ['order_id' => ['required', 'uuid']])->validate()['order_id'];

        return Order::query()->whereKey(strtolower($id))->where('branch_id', $this->branchId())->first()
            ?? throw ValidationException::withMessages(['order' => 'This order is not at this branch.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function add(Order $order, User $by, array $data): array
    {
        $lines = validator($data, [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.id' => ['required', 'uuid', 'distinct'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.note' => ['nullable', 'string', 'max:160'],
        ])->validate()['lines'];

        $this->orders->add($order, $by, array_values(array_map(fn (array $line) => [
            'id' => strtolower((string) $line['id']),
            'item_id' => (string) $line['item_id'],
            'quantity' => (int) $line['quantity'],
            'note' => $line['note'] ?? null,
        ], $lines)));

        return [];
    }

    /**
     * A line already gone is gone: nothing to do.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function remove(Order $order, array $data): array
    {
        $lineId = validator($data, ['line_id' => ['required', 'uuid']])->validate()['line_id'];
        $line = OrderLine::query()->whereKey(strtolower($lineId))->where('order_id', $order->id)->first();
        if ($line !== null) {
            $this->orders->remove($order, $line);
        }

        return [];
    }

    /**
     * Nothing new (another phone sent it already): nothing to do.
     *
     * @return array<string, mixed>
     */
    private function send(Order $order, User $by): array
    {
        if (! $order->lines()->whereNull('kitchen_ticket_id')->whereNull('voided_at')->exists()) {
            return ['ticket' => null];
        }

        return ['ticket' => $this->orders->send($order, $by)->number];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pay(Order $order, User $by, array $data): array
    {
        $pay = validator($data, [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'tendered_minor' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $paid = $this->orders->pay(
            $order,
            $by,
            PaymentMethod::from($pay['payment_method']),
            isset($pay['tendered_minor']) ? (int) $pay['tendered_minor'] : null,
            $pay['payment_reference'] ?? null,
        );
        $bill = $paid->bill()->firstOrFail();

        return ['bill_id' => $bill->id, 'bill_number' => $bill->number, 'total_minor' => $bill->total_minor, 'change_minor' => $bill->change_minor];
    }
}
