<?php

namespace App\Modules\Inventory\Enums;

/**
 * Why stock changed. Quantities are signed: receipts and opening stock add,
 * sales take, voids and returns put back, adjustments go either way,
 * approved disposals take, transfers take from the sending branch and add
 * to the receiving one (or back to the sender when cancelled).
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case SupplierReturn = 'supplier_return';
    case Disposal = 'disposal';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
}
