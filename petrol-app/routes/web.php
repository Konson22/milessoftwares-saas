<?php

use App\Http\Controllers\AttendantController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PumpController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\ReadingController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TankController;
use App\Http\Controllers\TransferController;
use App\Petrol\Permissions;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
| Served at {workspace}/petrol. The proxy must not strip the prefix.
| "platform" requires a signed-in member entitled to this product.
| "branch" is the station they are working in. "branch.can" checks a
| permission there.
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('branch', [BranchController::class, 'edit'])->name('branch.edit');
        Route::put('branch', [BranchController::class, 'update'])->name('branch.update');

        Route::get('overview', [OverviewController::class, 'index'])->name('overview.index');
        Route::post('overview/main', [OverviewController::class, 'designate'])->name('overview.main');

        // Company-wide management: every station, the pump staff, the stock.
        Route::get('stations', [StationController::class, 'index'])->name('stations.index');
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->middleware('throttle:60,1')->name('staff.store');
        Route::put('staff/{attendant}', [StaffController::class, 'update'])->whereUuid('attendant')->name('staff.update');
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::middleware('branch')->group(function () {
            Route::get('/', HomeController::class)->name('home');

            Route::middleware('branch.can:'.Permissions::STATIONS_MANAGE.','.Permissions::PRICES_MANAGE.','.Permissions::PRODUCTS_MANAGE)->group(function () {
                Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
            });
            Route::post('settings/station', [SettingsController::class, 'save'])->middleware('branch.can:'.Permissions::STATIONS_MANAGE)->name('settings.station');
            Route::post('settings/schedules', [SettingsController::class, 'schedule'])->middleware('branch.can:'.Permissions::STATIONS_MANAGE)->name('settings.schedules.store');
            Route::put('settings/schedules/{schedule}', [SettingsController::class, 'updateSchedule'])->middleware('branch.can:'.Permissions::STATIONS_MANAGE)->whereUuid('schedule')->name('settings.schedules.update');
            Route::post('settings/prices', [SettingsController::class, 'price'])->middleware('branch.can:'.Permissions::PRICES_MANAGE)->name('settings.prices.store');
            Route::get('currencies', [CurrencyController::class, 'index'])->middleware('branch.can:'.Permissions::CURRENCIES_VIEW.','.Permissions::CURRENCIES_MANAGE.','.Permissions::CURRENCIES_ENABLE)->name('currencies.index');
            Route::post('currencies', [CurrencyController::class, 'store'])->middleware('branch.can:'.Permissions::CURRENCIES_MANAGE)->name('currencies.store');
            Route::put('currencies/{currency}', [CurrencyController::class, 'update'])->middleware('branch.can:'.Permissions::CURRENCIES_MANAGE)->whereUuid('currency')->name('currencies.update');
            Route::put('currencies-station', [CurrencyController::class, 'station'])->middleware('branch.can:'.Permissions::CURRENCIES_ENABLE)->name('currencies.station');
            Route::get('rates', [RateController::class, 'index'])->middleware('branch.can:'.Permissions::RATES_MANAGE.','.Permissions::PRICES_MANAGE)->name('rates.index');
            Route::post('rates', [RateController::class, 'store'])->middleware('branch.can:'.Permissions::RATES_MANAGE)->name('rates.store');
            Route::put('settings/payments/{method}', [SettingsController::class, 'payment'])->middleware('branch.can:'.Permissions::STATIONS_MANAGE)->whereUuid('method')->name('settings.payments.update');

            Route::get('products', [ProductController::class, 'index'])->middleware('branch.can:'.Permissions::TANKS_VIEW.','.Permissions::PRODUCTS_MANAGE.','.Permissions::PRICES_MANAGE)->name('products.index');
            Route::post('products', [ProductController::class, 'store'])->middleware('branch.can:'.Permissions::PRODUCTS_MANAGE)->name('products.store');
            Route::put('products/{product}', [ProductController::class, 'update'])->middleware('branch.can:'.Permissions::PRODUCTS_MANAGE)->whereUuid('product')->name('products.update');

            Route::middleware('branch.can:'.Permissions::TANKS_VIEW)->group(function () {
                Route::get('tanks', [TankController::class, 'index'])->name('tanks.index');
                Route::get('tanks/{tank}', [TankController::class, 'show'])->whereUuid('tank')->name('tanks.show');
            });
            Route::post('tanks', [TankController::class, 'store'])->middleware('branch.can:'.Permissions::TANKS_MANAGE)->name('tanks.store');
            Route::put('tanks/{tank}', [TankController::class, 'update'])->middleware('branch.can:'.Permissions::TANKS_MANAGE)->whereUuid('tank')->name('tanks.update');
            Route::post('tanks/{tank}/opening', [TankController::class, 'opening'])->middleware('branch.can:'.Permissions::STOCK_ADJUST)->whereUuid('tank')->name('tanks.opening');
            Route::post('tanks/{tank}/chart', [TankController::class, 'chart'])->middleware('branch.can:'.Permissions::CHARTS_MANAGE)->whereUuid('tank')->name('tanks.chart');
            Route::post('tanks/{tank}/measure', [TankController::class, 'measure'])->middleware('branch.can:'.Permissions::MEASUREMENTS_RECORD.','.Permissions::STOCK_ADJUST)->whereUuid('tank')->name('tanks.measure');
            Route::post('tanks/{tank}/water', [TankController::class, 'clearWater'])->middleware('branch.can:'.Permissions::MEASUREMENTS_VERIFY)->whereUuid('tank')->name('tanks.water');
            Route::post('measurements/{measurement}/verify', [TankController::class, 'verifyMeasurement'])->middleware('branch.can:'.Permissions::MEASUREMENTS_VERIFY)->whereUuid('measurement')->name('measurements.verify');
            Route::post('measurements/{measurement}/adjust', [TankController::class, 'adjust'])->middleware('branch.can:'.Permissions::STOCK_ADJUST)->whereUuid('measurement')->name('measurements.adjust');
            Route::get('deliveries', [DeliveryController::class, 'index'])->middleware('branch.can:'.Permissions::RECEIVING_RECORD.','.Permissions::RECEIVING_APPROVE.','.Permissions::PURCHASES_VIEW)->name('deliveries.index');
            Route::post('deliveries', [DeliveryController::class, 'store'])->middleware('branch.can:'.Permissions::RECEIVING_RECORD.','.Permissions::PURCHASES_MANAGE)->name('deliveries.store');
            Route::post('deliveries/{receipt}/complete', [DeliveryController::class, 'complete'])->middleware('branch.can:'.Permissions::RECEIVING_RECORD.','.Permissions::PURCHASES_MANAGE)->whereUuid('receipt')->name('deliveries.complete');
            Route::post('deliveries/{receipt}/approve', [DeliveryController::class, 'approve'])->middleware('branch.can:'.Permissions::RECEIVING_APPROVE)->whereUuid('receipt')->name('deliveries.approve');
            Route::get('calibration', [CalibrationController::class, 'index'])->middleware('branch.can:'.Permissions::CALIBRATION_RECORD.','.Permissions::EQUIPMENT_MANAGE.','.Permissions::PUMPS_VIEW)->name('calibration.index');
            Route::post('calibration', [CalibrationController::class, 'store'])->middleware('branch.can:'.Permissions::CALIBRATION_RECORD)->name('calibration.store');
            Route::post('calibration/draws', [CalibrationController::class, 'draw'])->middleware('branch.can:'.Permissions::CALIBRATION_RECORD)->name('calibration.draws.store');
            Route::post('calibration/draws/{draw}/approve', [CalibrationController::class, 'approveDraw'])->middleware('branch.can:'.Permissions::CALIBRATION_APPROVE)->whereUuid('draw')->name('calibration.draws.approve');
            Route::post('calibration/draws/{draw}/return', [CalibrationController::class, 'returnDraw'])->middleware('branch.can:'.Permissions::CALIBRATION_APPROVE)->whereUuid('draw')->name('calibration.draws.return');
            Route::post('calibration/events', [CalibrationController::class, 'event'])->middleware('branch.can:'.Permissions::EQUIPMENT_MANAGE)->name('calibration.events.store');

            Route::middleware('branch.can:'.Permissions::PUMPS_VIEW)->group(function () {
                Route::get('pumps', [PumpController::class, 'index'])->name('pumps.index');
            });
            Route::middleware('branch.can:'.Permissions::PUMPS_MANAGE)->group(function () {
                Route::post('pumps', [PumpController::class, 'store'])->name('pumps.store');
                Route::put('pumps/{pump}', [PumpController::class, 'update'])->whereUuid('pump')->name('pumps.update');
                Route::post('pumps/{pump}/nozzles', [PumpController::class, 'nozzle'])->whereUuid('pump')->name('pumps.nozzles.store');
                Route::post('nozzles/{nozzle}/connect', [PumpController::class, 'connect'])->whereUuid('nozzle')->name('nozzles.connect');
            });

            Route::get('readings', [ReadingController::class, 'index'])->middleware('branch.can:'.Permissions::SHIFTS_VIEW)->name('readings.index');

            Route::middleware('branch.can:'.Permissions::ATTENDANTS_VIEW)->group(function () {
                Route::get('attendants', [AttendantController::class, 'index'])->name('attendants.index');
            });
            Route::post('attendants', [AttendantController::class, 'store'])->middleware('branch.can:'.Permissions::ATTENDANTS_MANAGE)->name('attendants.store');
            Route::put('attendants/{attendant}', [AttendantController::class, 'update'])->middleware('branch.can:'.Permissions::ATTENDANTS_MANAGE)->whereUuid('attendant')->name('attendants.update');

            Route::middleware('branch.can:'.Permissions::SHIFTS_VIEW)->group(function () {
                Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
                Route::get('shifts/{shift}', [ShiftController::class, 'show'])->whereUuid('shift')->name('shifts.show');
                Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
                Route::get('assignments', [ShiftController::class, 'assignments'])->name('assignments.index');
            });
            Route::post('shifts', [ShiftController::class, 'store'])->middleware('branch.can:'.Permissions::SHIFTS_OPEN)->name('shifts.store');
            Route::post('shifts/{shift}/assignments', [ShiftController::class, 'assign'])->middleware('branch.can:'.Permissions::SHIFTS_OPEN.','.Permissions::ASSIGNMENTS_CREATE)->whereUuid('shift')->name('shifts.assignments.store');
            Route::delete('shifts/{shift}/assignments/{assignment}', [ShiftController::class, 'unassign'])->middleware('branch.can:'.Permissions::SHIFTS_OPEN)->whereUuid(['shift', 'assignment'])->name('shifts.assignments.destroy');
            Route::post('shifts/{shift}/submit', [ShiftController::class, 'submit'])->middleware('branch.can:'.Permissions::SHIFTS_CLOSE)->whereUuid('shift')->name('shifts.submit');
            Route::post('shifts/{shift}/review', [ShiftController::class, 'review'])->middleware('branch.can:'.Permissions::SHIFTS_APPROVE)->whereUuid('shift')->name('shifts.review');
            Route::post('shifts/{shift}/approve', [ShiftController::class, 'approve'])->middleware('branch.can:'.Permissions::SHIFTS_APPROVE)->whereUuid('shift')->name('shifts.approve');
            Route::post('shifts/{shift}/reject', [ShiftController::class, 'reject'])->middleware('branch.can:'.Permissions::SHIFTS_APPROVE)->whereUuid('shift')->name('shifts.reject');
            Route::post('shifts/{shift}/reopen', [ShiftController::class, 'reopen'])->middleware('branch.can:'.Permissions::SHIFTS_APPROVE)->whereUuid('shift')->name('shifts.reopen');
            Route::get('handovers', [HandoverController::class, 'index'])->middleware('branch.can:'.Permissions::ASSIGNMENTS_VIEW.','.Permissions::HANDOVERS_INITIATE.','.Permissions::SHIFTS_VIEW)->name('handovers.index');
            Route::post('handovers', [HandoverController::class, 'store'])->middleware('branch.can:'.Permissions::HANDOVERS_INITIATE)->name('handovers.store');
            Route::post('handovers/{handover}/confirm', [HandoverController::class, 'confirm'])->middleware('branch.can:'.Permissions::HANDOVERS_CONFIRM)->whereUuid('handover')->name('handovers.confirm');
            Route::post('handovers/{handover}/approve', [HandoverController::class, 'approve'])->middleware('branch.can:'.Permissions::HANDOVERS_APPROVE)->whereUuid('handover')->name('handovers.approve');
            Route::post('handovers/{handover}/reject', [HandoverController::class, 'reject'])->middleware('branch.can:'.Permissions::HANDOVERS_APPROVE)->whereUuid('handover')->name('handovers.reject');
            Route::post('handovers/reassign', [HandoverController::class, 'reassign'])->middleware('branch.can:'.Permissions::ASSIGNMENTS_REASSIGN)->name('handovers.reassign');
            Route::post('handovers/depart', [HandoverController::class, 'depart'])->middleware('branch.can:'.Permissions::ASSIGNMENTS_REASSIGN)->name('handovers.depart');
            Route::post('handovers/breaks', [HandoverController::class, 'startBreak'])->middleware('branch.can:'.Permissions::ASSIGNMENTS_REASSIGN)->name('handovers.breaks.store');
            Route::post('handovers/breaks/{shiftBreak}/end', [HandoverController::class, 'endBreak'])->middleware('branch.can:'.Permissions::ASSIGNMENTS_REASSIGN)->whereUuid('shiftBreak')->name('handovers.breaks.end');
            Route::get('exceptions', [HandoverController::class, 'exceptions'])->middleware('branch.can:'.Permissions::EXCEPTIONS_REVIEW.','.Permissions::SHIFTS_VIEW)->name('exceptions.index');
            Route::post('exceptions', [HandoverController::class, 'storeException'])->middleware('branch.can:'.Permissions::EXCEPTIONS_REVIEW.','.Permissions::HANDOVERS_INITIATE)->name('exceptions.store');
            Route::post('exceptions/{exception}/review', [HandoverController::class, 'reviewException'])->middleware('branch.can:'.Permissions::EXCEPTIONS_REVIEW)->whereUuid('exception')->name('exceptions.review');
            Route::post('exceptions/{exception}/resolve', [HandoverController::class, 'resolveException'])->middleware('branch.can:'.Permissions::EXCEPTIONS_RESOLVE)->whereUuid('exception')->name('exceptions.resolve');
            Route::post('exceptions/{exception}/close', [HandoverController::class, 'closeException'])->middleware('branch.can:'.Permissions::EXCEPTIONS_RESOLVE)->whereUuid('exception')->name('exceptions.close');
            Route::post('exceptions/{exception}/reopen', [HandoverController::class, 'reopenException'])->middleware('branch.can:'.Permissions::EXCEPTIONS_RESOLVE)->whereUuid('exception')->name('exceptions.reopen');
            Route::put('shifts/{shift}/readings/{reading}', [ShiftController::class, 'correct'])->middleware('branch.can:'.Permissions::METERS_CORRECT)->whereUuid(['shift', 'reading'])->name('shifts.readings.update');

            Route::get('sales/create', [SaleController::class, 'create'])->middleware('branch.can:'.Permissions::SALES_CREATE)->name('sales.create');
            Route::post('sales', [SaleController::class, 'store'])->middleware(['branch.can:'.Permissions::SALES_CREATE, 'throttle:60,1'])->name('sales.store');
            Route::middleware('branch.can:'.Permissions::SALES_VIEW)->group(function () {
                Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
                Route::get('sales/{sale}', [SaleController::class, 'show'])->whereUuid('sale')->name('sales.show');
            });
            Route::post('sales/{sale}/void', [SaleController::class, 'void'])->middleware('branch.can:'.Permissions::SALES_VOID)->whereUuid('sale')->name('sales.void');

            Route::middleware('branch.can:'.Permissions::CUSTOMERS_VIEW)->group(function () {
                Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereUuid('customer')->name('customers.show');
            });
            Route::post('customers', [CustomerController::class, 'store'])->middleware('branch.can:'.Permissions::CUSTOMERS_MANAGE)->name('customers.store');
            Route::post('customers/{customer}/payments', [CustomerController::class, 'pay'])->middleware(['branch.can:'.Permissions::CUSTOMERS_MANAGE, 'throttle:60,1'])->whereUuid('customer')->name('customers.payments.store');

            Route::middleware('branch.can:'.Permissions::PURCHASES_VIEW)->group(function () {
                Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
                Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
                Route::get('purchases/{order}', [PurchaseController::class, 'show'])->whereUuid('order')->name('purchases.show');
            });
            Route::post('suppliers', [SupplierController::class, 'store'])->middleware('branch.can:'.Permissions::PURCHASES_MANAGE)->name('suppliers.store');
            Route::post('purchases', [PurchaseController::class, 'store'])->middleware('branch.can:'.Permissions::PURCHASES_MANAGE)->name('purchases.store');
            Route::post('purchases/{order}/receive', [PurchaseController::class, 'receive'])->middleware(['branch.can:'.Permissions::PURCHASES_MANAGE, 'throttle:60,1'])->whereUuid('order')->name('purchases.receive');
            Route::post('receipts', [PurchaseController::class, 'receiveDirect'])->middleware(['branch.can:'.Permissions::PURCHASES_MANAGE, 'throttle:60,1'])->name('receipts.store');
            Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'pay'])->middleware('branch.can:'.Permissions::PAYABLES_PAY)->whereUuid('supplier')->name('suppliers.payments.store');

            Route::middleware('branch.can:'.Permissions::EXPENSES_MANAGE.','.Permissions::FINANCE_VIEW)->group(function () {
                Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            });
            Route::post('expenses', [ExpenseController::class, 'store'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->name('expenses.store');
            Route::post('expenses/categories', [ExpenseController::class, 'category'])->middleware('branch.can:'.Permissions::EXPENSE_CATEGORIES)->name('expenses.categories.store');
            Route::post('expenses/schedules', [ExpenseController::class, 'schedule'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->name('expenses.schedules.store');
            Route::post('expenses/schedules/generate', [ExpenseController::class, 'generate'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->name('expenses.schedules.generate');
            Route::post('expenses/schedules/{schedule}/pause', [ExpenseController::class, 'pause'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->whereUuid('schedule')->name('expenses.schedules.pause');
            Route::post('expenses/schedules/{schedule}/resume', [ExpenseController::class, 'resume'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->whereUuid('schedule')->name('expenses.schedules.resume');
            Route::post('expenses/schedules/{schedule}/end', [ExpenseController::class, 'end'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->whereUuid('schedule')->name('expenses.schedules.end');
            Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('branch.can:'.Permissions::EXPENSES_MANAGE)->whereUuid('expense')->name('expenses.approve');

            Route::middleware('branch.can:'.Permissions::SERVICE_MANAGE.','.Permissions::SERVICE_WORK)->group(function () {
                Route::get('service', [ServiceController::class, 'index'])->name('service.index');
            });
            Route::post('service/bays', [ServiceController::class, 'bay'])->middleware('branch.can:'.Permissions::SERVICE_MANAGE)->name('service.bays.store');
            Route::post('service/offerings', [ServiceController::class, 'offering'])->middleware('branch.can:'.Permissions::SERVICE_MANAGE)->name('service.offerings.store');
            Route::post('service/vehicles', [ServiceController::class, 'vehicle'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->name('service.vehicles.store');
            Route::post('service/orders', [ServiceController::class, 'open'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->name('service.orders.store');
            Route::post('service/orders/{order}/parts', [ServiceController::class, 'part'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->whereUuid('order')->name('service.orders.parts');
            Route::post('service/lines/{line}/issue', [ServiceController::class, 'issue'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->whereUuid('line')->name('service.lines.issue');
            Route::post('service/orders/{order}/status', [ServiceController::class, 'advance'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->whereUuid('order')->name('service.orders.status');
            Route::post('service/orders/{order}/payments', [ServiceController::class, 'pay'])->middleware('branch.can:'.Permissions::SERVICE_WORK)->whereUuid('order')->name('service.orders.payments');

            Route::middleware('branch.can:'.Permissions::STORE_MANAGE.','.Permissions::STORE_MOVE)->group(function () {
                Route::get('store', [StoreController::class, 'index'])->name('stores.index');
            });
            Route::post('store/stores', [StoreController::class, 'saveStore'])->middleware('branch.can:'.Permissions::STORE_MANAGE)->name('stores.stores');
            Route::post('store/products', [StoreController::class, 'saveProduct'])->middleware('branch.can:'.Permissions::STORE_MANAGE)->name('stores.products');
            Route::post('store/receive', [StoreController::class, 'receive'])->middleware('branch.can:'.Permissions::STORE_MOVE)->name('stores.receive');
            Route::post('store/issue', [StoreController::class, 'issue'])->middleware('branch.can:'.Permissions::STORE_MOVE)->name('stores.issue');
            Route::post('store/adjust', [StoreController::class, 'adjust'])->middleware('branch.can:'.Permissions::STORE_MANAGE)->name('stores.adjust');
            Route::post('store/count', [StoreController::class, 'count'])->middleware('branch.can:'.Permissions::STORE_MOVE)->name('stores.count');

            Route::get('transfers', [TransferController::class, 'index'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE.','.Permissions::TRANSFERS_APPROVE)->name('transfers.index');
            Route::post('transfers/fuel', [TransferController::class, 'requestFuel'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->name('transfers.fuel.store');
            Route::post('transfers/fuel/{transfer}/approve', [TransferController::class, 'approveFuel'])->middleware('branch.can:'.Permissions::TRANSFERS_APPROVE)->whereUuid('transfer')->name('transfers.fuel.approve');
            Route::post('transfers/fuel/{transfer}/dispatch', [TransferController::class, 'dispatchFuel'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.fuel.dispatch');
            Route::post('transfers/fuel/{transfer}/receive', [TransferController::class, 'receiveFuel'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.fuel.receive');
            Route::post('transfers/fuel/{transfer}/complete', [TransferController::class, 'completeFuel'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.fuel.complete');
            Route::post('transfers/store', [TransferController::class, 'requestStore'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->name('transfers.store.store');
            Route::post('transfers/store/{transfer}/approve', [TransferController::class, 'approveStore'])->middleware('branch.can:'.Permissions::TRANSFERS_APPROVE)->whereUuid('transfer')->name('transfers.store.approve');
            Route::post('transfers/store/{transfer}/dispatch', [TransferController::class, 'dispatchStore'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.store.dispatch');
            Route::post('transfers/store/{transfer}/receive', [TransferController::class, 'receiveStore'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.store.receive');
            Route::post('transfers/store/{transfer}/complete', [TransferController::class, 'completeStore'])->middleware('branch.can:'.Permissions::TRANSFERS_MANAGE)->whereUuid('transfer')->name('transfers.store.complete');

            Route::middleware('branch.can:'.Permissions::CASH_MANAGE.','.Permissions::FINANCE_VIEW.','.Permissions::CASH_VIEW.','.Permissions::CASH_SUBMIT.','.Permissions::CASH_CONFIRM)->group(function () {
                Route::get('cash', [CashController::class, 'index'])->name('cash.index');
            });
            Route::post('cash/petty', [CashController::class, 'fund'])->middleware('branch.can:'.Permissions::CASH_MANAGE)->name('cash.petty');
            Route::post('cash/remittances', [CashController::class, 'remit'])->middleware('branch.can:'.Permissions::CASH_SUBMIT)->name('cash.remit');
            Route::post('cash/transfers/{transfer}/confirm', [CashController::class, 'confirm'])->middleware('branch.can:'.Permissions::CASH_CONFIRM)->whereUuid('transfer')->name('cash.confirm');
            Route::post('cash/transfers/{transfer}/reject', [CashController::class, 'reject'])->middleware('branch.can:'.Permissions::CASH_REJECT)->whereUuid('transfer')->name('cash.reject');
            Route::post('cash/safes', [CashController::class, 'storeSafe'])->middleware('branch.can:'.Permissions::CASH_SAFES)->name('cash.safes.store');
            Route::post('cash/safes/transfer', [CashController::class, 'toSafe'])->middleware('branch.can:'.Permissions::CASH_TRANSFER)->name('cash.safes.receive');
            Route::post('cash/safes/move', [CashController::class, 'betweenSafes'])->middleware('branch.can:'.Permissions::CASH_TRANSFER)->name('cash.safes.move');
            Route::post('cash/payouts', [CashController::class, 'payout'])->middleware('branch.can:'.Permissions::CASH_TRANSFER)->name('cash.payout');
            Route::post('cash/adjustments', [CashController::class, 'adjust'])->middleware('branch.can:'.Permissions::CASH_ADJUST)->name('cash.adjust');
            Route::post('cash/deposits', [CashController::class, 'deposit'])->middleware('branch.can:'.Permissions::CASH_DEPOSIT)->name('cash.deposits.store');
            Route::post('cash/deposits/{deposit}/submit', [CashController::class, 'submitDeposit'])->middleware('branch.can:'.Permissions::CASH_DEPOSIT)->whereUuid('deposit')->name('cash.deposits.submit');
            Route::post('cash/deposits/{deposit}/confirm', [CashController::class, 'confirmDeposit'])->middleware('branch.can:'.Permissions::CASH_DEPOSIT_CONFIRM)->whereUuid('deposit')->name('cash.deposits.confirm');
            Route::post('cash/deposits/{deposit}/reject', [CashController::class, 'rejectDeposit'])->middleware('branch.can:'.Permissions::CASH_DEPOSIT_CONFIRM)->whereUuid('deposit')->name('cash.deposits.reject');
            Route::post('cash/counts', [CashController::class, 'count'])->middleware('branch.can:'.Permissions::CASH_RECONCILE)->name('cash.counts');

            Route::get('finance', [FinanceController::class, 'index'])->middleware('branch.can:'.Permissions::FINANCE_VIEW)->name('finance.index');
            Route::get('reports', [ReportController::class, 'index'])->middleware('branch.can:'.Permissions::REPORTS_VIEW)->name('reports.index');
            Route::get('audit', [AuditController::class, 'index'])->middleware('branch.can:'.Permissions::AUDIT_VIEW)->name('audit.index');
        });
    });
