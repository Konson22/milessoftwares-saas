<?php

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\CounterWorkflow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One branch's counter: its workflow, when the customer must be named, and
 * what it prints. A branch that never saved any works as a direct sale
 * with no printing and no customer required (forBranch()).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $branch_id
 * @property CounterWorkflow $workflow
 * @property string $direct_payment paid|unpaid|choose: a direct sale paid now, handed over unpaid, or either
 * @property bool $shifts_required money is taken only in an open shift (Tills)
 * @property int|null $default_float_minor the usual starting cash of a shift
 * @property bool $blind_close cashiers count without seeing what the drawer should hold
 * @property bool $manager_closes_shifts only those who manage tills close shifts
 * @property bool $require_customer_always
 * @property bool $require_customer_for_restricted
 * @property int|null $require_customer_above_minor
 * @property bool $print_receipt_after_payment
 * @property bool $print_order_slip
 * @property bool $print_collection_slip
 * @property bool $print_receipt_after_dispensing
 * @property string|null $printer_name
 * @property string $paper_size 58mm|80mm|a4
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property string|null $business_name
 * @property string|null $address
 * @property string|null $contact
 * @property string|null $logo a data: URL
 * @property bool $show_logo
 * @property bool $show_address
 * @property bool $show_contact
 * @property bool $show_customer
 * @property bool $show_staff
 * @property bool $show_barcode
 * @property bool $slip_prices
 */
class BranchCounterSettings extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PAPER_SIZES = ['58mm', '80mm', 'a4'];

    public const DIRECT_PAYMENTS = ['paid', 'unpaid', 'choose'];

    protected $guarded = ['organization_id'];

    protected $attributes = [
        'workflow' => 'direct',
        'direct_payment' => 'paid',
        'shifts_required' => true,
        'blind_close' => false,
        'manager_closes_shifts' => false,
        'require_customer_always' => false,
        'require_customer_for_restricted' => false,
        'print_receipt_after_payment' => false,
        'print_order_slip' => false,
        'print_collection_slip' => false,
        'print_receipt_after_dispensing' => false,
        'paper_size' => '80mm',
        'show_logo' => true,
        'show_address' => true,
        'show_contact' => true,
        'show_customer' => true,
        'show_staff' => true,
        'show_barcode' => true,
        'slip_prices' => true,
    ];

    protected function casts(): array
    {
        return [
            'workflow' => CounterWorkflow::class,
            'shifts_required' => 'boolean',
            'default_float_minor' => 'integer',
            'blind_close' => 'boolean',
            'manager_closes_shifts' => 'boolean',
            'require_customer_always' => 'boolean',
            'require_customer_for_restricted' => 'boolean',
            'require_customer_above_minor' => 'integer',
            'print_receipt_after_payment' => 'boolean',
            'print_order_slip' => 'boolean',
            'print_collection_slip' => 'boolean',
            'print_receipt_after_dispensing' => 'boolean',
            'show_logo' => 'boolean',
            'show_address' => 'boolean',
            'show_contact' => 'boolean',
            'show_customer' => 'boolean',
            'show_staff' => 'boolean',
            'show_barcode' => 'boolean',
            'slip_prices' => 'boolean',
        ];
    }

    /**
     * The branch's settings, or the defaults (unsaved) when it has none.
     */
    public static function forBranch(string $branchId, string $application): self
    {
        return self::query()
            ->where('branch_id', $branchId)
            ->where('application', $application)
            ->first() ?? new self(['branch_id' => $branchId, 'application' => $application]);
    }

    /**
     * Whether a direct sale here may be paid at the counter.
     */
    public function sellsPaid(): bool
    {
        return $this->direct_payment !== 'unpaid';
    }

    /**
     * Whether a direct sale here may be handed over unpaid.
     */
    public function sellsUnpaid(): bool
    {
        return $this->direct_payment !== 'paid';
    }
}
