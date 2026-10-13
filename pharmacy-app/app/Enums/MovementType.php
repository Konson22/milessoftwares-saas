<?php

namespace App\Enums;

/**
 * Why stock changed. Quantities are signed: receipts and opening stock add,
 * sales take, voids put back, adjustments go either way.
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case Adjustment = 'adjustment';
    case SupplierReturn = 'supplier_return';
}
