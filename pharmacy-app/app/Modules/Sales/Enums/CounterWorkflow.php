<?php

namespace App\Modules\Sales\Enums;

/**
 * How a branch's counter turns a basket into a sale. A direct sale is paid
 * and handed over at once; the others take an order first, then payment and
 * hand-over happen in the branch's order, often at different counters.
 * Payment always makes the sale (Sell); hand-over is dispensing or
 * collection.
 */
enum CounterWorkflow: string
{
    case Direct = 'direct';
    case OrderPayDispense = 'order_pay_dispense';
    case OrderDispensePay = 'order_dispense_pay';
    case OrderPayCollect = 'order_pay_collect';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct sale',
            self::OrderPayDispense => 'Order → payment → dispensing',
            self::OrderDispensePay => 'Order → dispensing → payment',
            self::OrderPayCollect => 'Order → payment → collection',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Direct => 'One person sells, takes payment and hands over at once.',
            self::OrderPayDispense => 'The order is paid at the cashier, then dispensed at the dispensary.',
            self::OrderDispensePay => 'The order is dispensed first; the customer pays on the way out.',
            self::OrderPayCollect => 'The order is paid at the cashier, then collected at a collection point.',
        };
    }

    public function takesOrders(): bool
    {
        return $this !== self::Direct;
    }

    /**
     * Whether payment comes before hand-over.
     */
    public function paysFirst(): bool
    {
        return $this !== self::OrderDispensePay;
    }

    /**
     * What handing the goods over is called at this counter.
     */
    public function handOver(): string
    {
        return $this === self::OrderPayCollect ? 'collection' : 'dispensing';
    }
}
