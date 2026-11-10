<?php

namespace App\Petrol;

use App\Models\Customer;
use App\Models\ServiceBay;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Models\WorkPayment;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service-bay jobs. A part on an estimate does not leave the store until
 * it is issued, and it can be issued only once.
 */
class ServiceDesk
{
    public function __construct(
        private readonly Stores $stores,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function open(string $branchId, ServiceBay $bay, Customer $customer, Vehicle $vehicle, ?int $odometer, ?string $technician, User $by): WorkOrder
    {
        if ($bay->branch_id !== $branchId || $bay->status !== ServiceBay::OPEN) {
            throw ValidationException::withMessages(['service_bay_id' => 'Choose an open bay at this station.']);
        }
        if ($vehicle->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is not registered to this customer.']);
        }

        return DB::transaction(function () use ($branchId, $bay, $customer, $vehicle, $odometer, $technician, $by) {
            $order = WorkOrder::query()->create([
                'branch_id' => $branchId,
                'service_bay_id' => $bay->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'number' => $this->numbers->next('work_order', 'WO'),
                'status' => WorkOrder::OPEN,
                'odometer' => $odometer,
                'technician' => $technician,
                'opened_by' => $by->id,
                'opened_at' => now(),
            ]);
            $this->audit->record($by, 'work_order.opened', $order, null, ['number' => $order->number], $branchId);

            return $order;
        });
    }

    public function addService(WorkOrder $order, string $description, int $amount, User $by): WorkOrderLine
    {
        return $this->line($order, WorkOrderLine::SERVICE, $description, 1000, $amount, null, null, $by);
    }

    public function addPart(WorkOrder $order, Store $store, StoreProduct $product, int $milli, int $amount, User $by): WorkOrderLine
    {
        if ($store->branch_id !== $order->branch_id) {
            throw ValidationException::withMessages(['store_id' => 'Choose a store at this station.']);
        }

        return $this->line($order, WorkOrderLine::PART, $product->name, $milli, $amount, $store->id, $product->id, $by);
    }

    public function issue(WorkOrderLine $line, User $by): WorkOrderLine
    {
        return DB::transaction(function () use ($line, $by) {
            $locked = WorkOrderLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $order = WorkOrder::query()->whereKey($locked->work_order_id)->lockForUpdate()->firstOrFail();
            if ($locked->kind !== WorkOrderLine::PART || $locked->store_id === null || $locked->store_product_id === null) {
                throw ValidationException::withMessages(['line' => 'Only a store part can be issued.']);
            }
            if ($locked->issued) {
                return $locked;
            }
            if (! in_array($order->status, [WorkOrder::OPEN, WorkOrder::IN_PROGRESS], true)) {
                throw ValidationException::withMessages(['line' => 'Parts can be issued only while the job is open.']);
            }

            $store = Store::query()->whereKey($locked->store_id)->firstOrFail();
            $product = StoreProduct::query()->whereKey($locked->store_product_id)->firstOrFail();
            $this->stores->issue($store, $product, $locked->quantity_milli, $by, 'work:'.$locked->id, WorkOrderLine::class, $locked->id);
            $locked->issued = true;
            $locked->save();
            if ($order->status === WorkOrder::OPEN) {
                $order->status = WorkOrder::IN_PROGRESS;
                $order->save();
            }
            $this->audit->record($by, 'work_order.part_issued', $locked, null, ['quantity_milli' => $locked->quantity_milli], $order->branch_id);

            return $locked;
        });
    }

    public function advance(WorkOrder $order, string $to, int $labor, User $by): WorkOrder
    {
        $allowed = [
            WorkOrder::OPEN => WorkOrder::IN_PROGRESS,
            WorkOrder::IN_PROGRESS => WorkOrder::COMPLETED,
            WorkOrder::COMPLETED => WorkOrder::INVOICED,
            WorkOrder::INVOICED => WorkOrder::CLOSED,
        ];

        return DB::transaction(function () use ($order, $to, $labor, $by, $allowed) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (($allowed[$locked->status] ?? null) !== $to) {
                throw ValidationException::withMessages(['status' => 'This job is '.$locked->status.'.']);
            }
            if ($to === WorkOrder::COMPLETED) {
                $locked->labor_minor = $labor;
                $locked->completed_at = now();
            }
            if ($to === WorkOrder::INVOICED) {
                $parts = (int) $locked->lines()->where('issued', true)->sum('amount_minor');
                $services = (int) $locked->lines()->where('kind', WorkOrderLine::SERVICE)->sum('amount_minor');
                $locked->invoiced_minor = $locked->labor_minor + $parts + $services;
                $locked->invoiced_at = now();
            }
            if ($to === WorkOrder::CLOSED) {
                $paid = (int) $locked->payments()->sum('amount_minor');
                if ($paid < (int) $locked->invoiced_minor) {
                    throw ValidationException::withMessages(['status' => 'Record the payment before closing the job.']);
                }
                $locked->closed_at = now();
            }
            $locked->status = $to;
            $locked->save();
            $this->audit->record($by, 'work_order.'.$to, $locked, null, ['status' => $to], $locked->branch_id);

            return $locked;
        });
    }

    public function pay(WorkOrder $order, int $amount, string $kind, User $by, ?string $key): WorkPayment
    {
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
        }

        return DB::transaction(function () use ($order, $amount, $kind, $by, $key) {
            if ($key !== null) {
                $existing = WorkPayment::query()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [WorkOrder::INVOICED, WorkOrder::CLOSED], true)) {
                throw ValidationException::withMessages(['amount' => 'Invoice the job before taking payment.']);
            }
            $payment = WorkPayment::query()->create([
                'work_order_id' => $locked->id,
                'amount_minor' => $amount,
                'kind' => $kind,
                'idempotency_key' => $key,
                'recorded_by' => $by->id,
            ]);
            $this->audit->record($by, 'work_order.paid', $payment, null, ['amount_minor' => $amount], $locked->branch_id);

            return $payment;
        });
    }

    public function cancel(WorkOrder $order, User $by): WorkOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [WorkOrder::OPEN, WorkOrder::IN_PROGRESS], true)) {
                throw ValidationException::withMessages(['status' => 'This job can no longer be cancelled.']);
            }
            if ($locked->lines()->where('issued', true)->exists()) {
                throw ValidationException::withMessages(['status' => 'Return issued parts before cancelling.']);
            }
            $locked->status = WorkOrder::CANCELLED;
            $locked->save();
            $this->audit->record($by, 'work_order.cancelled', $locked, null, null, $locked->branch_id);

            return $locked;
        });
    }

    private function line(WorkOrder $order, string $kind, string $description, int $milli, int $amount, ?string $storeId, ?string $productId, User $by): WorkOrderLine
    {
        if (! in_array($order->status, [WorkOrder::OPEN, WorkOrder::IN_PROGRESS], true)) {
            throw ValidationException::withMessages(['description' => 'This job is no longer open.']);
        }

        return WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'kind' => $kind,
            'description' => $description,
            'quantity_milli' => $milli,
            'amount_minor' => $amount,
            'store_id' => $storeId,
            'store_product_id' => $productId,
            'issued' => false,
        ]);
    }
}
