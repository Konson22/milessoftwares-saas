<?php

namespace App\Http\Controllers\Api;

use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET api/v1/counter: how the branch's counter works for this member: what
 * they may do, the branch's rules (workflow, shifts, when the customer must
 * be named), how money is taken, what the receipt says, and their open
 * shift. The app shapes its screens on it; the server still checks
 * everything.
 */
class CounterController extends ApiController
{
    public function __invoke(Request $request, Tills $tills): JsonResponse
    {
        $settings = BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());
        $managing = $this->may('tills.manage');
        $till = $tills->openFor($this->user($request), $this->branch->id());

        return response()->json([
            'branch' => ['id' => $this->branch->id(), 'name' => $this->branch->require()->name, 'code' => $this->branch->require()->code],
            'can' => [
                'sell' => $this->may('sales.create'),
                'view_sales' => $this->may('sales.view'),
                'void' => $this->may('sales.void'),
                'discount' => $this->may('sales.discount'),
                'manage_tills' => $managing,
                'view_prescriptions' => $this->may('prescriptions.view'),
                'record_prescriptions' => $this->may('prescriptions.record'),
                'verify_prescriptions' => $this->may('prescriptions.verify'),
            ],
            'max_discount_percent' => $this->may('sales.discount') ? $this->industry->maxDiscountPercent() : 0,
            'counter' => [
                'workflow' => $settings->workflow->value,
                'workflow_label' => $settings->workflow->label(),
                // The app sells directly and paid; other counters work on the web.
                'sells_direct' => ! $settings->workflow->takesOrders() && $settings->sellsPaid(),
                'shifts_required' => $settings->shifts_required,
                'default_float_minor' => $settings->default_float_minor ?? 0,
                'blind_close' => $settings->blind_close && ! $managing,
                'manager_closes_shifts' => $settings->manager_closes_shifts,
                'require_customer_always' => $settings->require_customer_always,
                'require_customer_for_restricted' => $settings->require_customer_for_restricted,
                'require_customer_above_minor' => $settings->require_customer_above_minor,
            ],
            'payment_methods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::payments()),
            'receipt' => [
                'business_name' => $settings->business_name,
                'address' => $settings->show_address ? $settings->address : null,
                'contact' => $settings->show_contact ? $settings->contact : null,
                'header' => $settings->receipt_header,
                'footer' => $settings->receipt_footer,
                'show_customer' => $settings->show_customer,
                'show_staff' => $settings->show_staff,
                'paper_size' => $settings->paper_size,
            ],
            'till' => $till === null ? null : ['id' => $till->id, 'number' => $till->number],
        ]);
    }
}
