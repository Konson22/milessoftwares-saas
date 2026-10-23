<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Enums\CounterWorkflow;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\PriceList;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The counter settings of the branch being worked in: its workflow, the
 * price level it sells at, when the customer must be named, and what it
 * prints.
 */
class CounterSettingsController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function edit(): Response
    {
        $settings = BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());

        return Inertia::render($this->industry->page('counter/settings'), [
            'settings' => [
                'workflow' => $settings->workflow->value,
                'directPayment' => $settings->direct_payment,
                'shiftsRequired' => $settings->shifts_required,
                'defaultFloat' => $settings->default_float_minor === null ? '' : Money::toMajor($settings->default_float_minor),
                'blindClose' => $settings->blind_close,
                'managerClosesShifts' => $settings->manager_closes_shifts,
                'priceListId' => $settings->price_list_id ?? '',
                'requireCustomerAlways' => $settings->require_customer_always,
                'requireCustomerForRestricted' => $settings->require_customer_for_restricted,
                'requireCustomerAbove' => $settings->require_customer_above_minor === null ? '' : Money::toMajor($settings->require_customer_above_minor),
                'printReceiptAfterPayment' => $settings->print_receipt_after_payment,
                'printOrderSlip' => $settings->print_order_slip,
                'printCollectionSlip' => $settings->print_collection_slip,
                'printReceiptAfterDispensing' => $settings->print_receipt_after_dispensing,
                'printerName' => $settings->printer_name ?? '',
                'paperSize' => $settings->paper_size,
                'receiptHeader' => $settings->receipt_header ?? '',
                'receiptFooter' => $settings->receipt_footer ?? '',
                'businessName' => $settings->business_name ?? '',
                'address' => $settings->address ?? '',
                'contact' => $settings->contact ?? '',
                'logo' => $settings->logo,
                'showLogo' => $settings->show_logo,
                'showAddress' => $settings->show_address,
                'showContact' => $settings->show_contact,
                'showCustomer' => $settings->show_customer,
                'showStaff' => $settings->show_staff,
                'showBarcode' => $settings->show_barcode,
                'slipPrices' => $settings->slip_prices,
            ],
            'workflows' => array_map(fn (CounterWorkflow $workflow) => [
                'value' => $workflow->value,
                'label' => $workflow->label(),
                'description' => $workflow->description(),
            ], CounterWorkflow::cases()),
            'paperSizes' => BranchCounterSettings::PAPER_SIZES,
            // Price levels the branch can sell at (ShelfPrices).
            'priceLists' => PriceList::query()
                ->where('application', $this->industry->code())
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (PriceList $list) => ['id' => $list->id, 'name' => $list->name]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workflow' => ['required', Rule::enum(CounterWorkflow::class)],
            'direct_payment' => ['sometimes', Rule::in(BranchCounterSettings::DIRECT_PAYMENTS)],
            'shifts_required' => ['boolean'],
            'default_float' => ['nullable', 'string', Money::rule()],
            'blind_close' => ['boolean'],
            'manager_closes_shifts' => ['boolean'],
            'price_list_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('price_lists', 'id')
                ->where('organization_id', app(CurrentTenant::class)->id())
                ->where('application', $this->industry->code())],
            'require_customer_always' => ['boolean'],
            'require_customer_for_restricted' => ['boolean'],
            'require_customer_above' => ['nullable', 'string', Money::rule()],
            'print_receipt_after_payment' => ['boolean'],
            'print_order_slip' => ['boolean'],
            'print_collection_slip' => ['boolean'],
            'print_receipt_after_dispensing' => ['boolean'],
            'printer_name' => ['nullable', 'string', 'max:120'],
            'paper_size' => ['required', Rule::in(BranchCounterSettings::PAPER_SIZES)],
            'receipt_header' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:256'],
            'remove_logo' => ['boolean'],
            'show_logo' => ['boolean'],
            'show_address' => ['boolean'],
            'show_contact' => ['boolean'],
            'show_customer' => ['boolean'],
            'show_staff' => ['boolean'],
            'show_barcode' => ['boolean'],
            'slip_prices' => ['boolean'],
        ]);

        $settings = BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());
        $workflow = CounterWorkflow::from($validated['workflow']);

        // Orders already under way finish in the workflow they started in;
        // a branch going back to direct sales finishes them first.
        if (! $workflow->takesOrders() && $settings->workflow->takesOrders() && CounterOrder::query()
            ->where('branch_id', $this->branch->id())
            ->where('application', $this->industry->code())
            ->whereIn('status', [CounterOrder::OPEN, CounterOrder::PAID, CounterOrder::HANDED_OVER])
            ->exists()) {
            throw ValidationException::withMessages(['workflow' => 'Finish or cancel the open orders before selling directly.']);
        }

        $text = fn (string $key) => ($value = trim((string) ($validated[$key] ?? ''))) === '' ? null : $value;
        $logo = $request->file('logo');

        $settings->fill([
            'workflow' => $workflow,
            'direct_payment' => $validated['direct_payment'] ?? $settings->direct_payment,
            'shifts_required' => (bool) ($validated['shifts_required'] ?? $settings->shifts_required),
            'default_float_minor' => ($validated['default_float'] ?? null) === null ? null : Money::toMinor($validated['default_float']),
            'blind_close' => (bool) ($validated['blind_close'] ?? false),
            'manager_closes_shifts' => (bool) ($validated['manager_closes_shifts'] ?? false),
            'price_list_id' => array_key_exists('price_list_id', $validated) ? $validated['price_list_id'] : $settings->price_list_id,
            'require_customer_always' => (bool) ($validated['require_customer_always'] ?? false),
            'require_customer_for_restricted' => (bool) ($validated['require_customer_for_restricted'] ?? false),
            'require_customer_above_minor' => ($validated['require_customer_above'] ?? null) === null ? null : Money::toMinor($validated['require_customer_above']),
            'print_receipt_after_payment' => (bool) ($validated['print_receipt_after_payment'] ?? false),
            'print_order_slip' => (bool) ($validated['print_order_slip'] ?? false),
            'print_collection_slip' => (bool) ($validated['print_collection_slip'] ?? false),
            'print_receipt_after_dispensing' => (bool) ($validated['print_receipt_after_dispensing'] ?? false),
            'printer_name' => $text('printer_name'),
            'paper_size' => $validated['paper_size'],
            'receipt_header' => $text('receipt_header'),
            'receipt_footer' => $text('receipt_footer'),
            'business_name' => $text('business_name'),
            'address' => $text('address'),
            'contact' => $text('contact'),
            'show_logo' => (bool) ($validated['show_logo'] ?? false),
            'show_address' => (bool) ($validated['show_address'] ?? false),
            'show_contact' => (bool) ($validated['show_contact'] ?? false),
            'show_customer' => (bool) ($validated['show_customer'] ?? false),
            'show_staff' => (bool) ($validated['show_staff'] ?? false),
            'show_barcode' => (bool) ($validated['show_barcode'] ?? false),
            'slip_prices' => (bool) ($validated['slip_prices'] ?? false),
        ]);
        // The logo is small and kept with the settings, so printing needs no
        // file storage.
        if ($logo !== null) {
            $settings->logo = 'data:'.$logo->getMimeType().';base64,'.base64_encode((string) $logo->get());
        } elseif ((bool) ($validated['remove_logo'] ?? false)) {
            $settings->logo = null;
        }
        $settings->save();

        return back()->with('success', 'Counter settings saved.');
    }
}
