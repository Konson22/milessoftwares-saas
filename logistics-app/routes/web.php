<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Product pages
|--------------------------------------------------------------------------
|
| Served at {workspace}/<path>: every route here (and every platform-client
| route) carries the path prefix. The proxy must not strip it. The
| "platform" middleware resolves the workspace from the host and requires a
| signed-in member whose platform access allows this product.
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        // Customers are looked after by whoever books their shipments.
        Route::middleware('can:logistics.shipments.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        });
        Route::middleware('can:logistics.shipments.manage')->group(function () {
            Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->whereUuid('customer')->name('customers.edit');
            Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereUuid('customer')->name('customers.update');
        });

        // Shipments: booking and their details.
        Route::middleware('can:logistics.shipments.manage')->group(function () {
            Route::get('shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
            Route::post('shipments', [ShipmentController::class, 'store'])->name('shipments.store');
            Route::get('shipments/{shipment}/edit', [ShipmentController::class, 'edit'])->whereUuid('shipment')->name('shipments.edit');
            Route::put('shipments/{shipment}', [ShipmentController::class, 'update'])->whereUuid('shipment')->name('shipments.update');
            Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->whereUuid('shipment')->name('shipments.cancel');
        });
        Route::middleware('can:logistics.shipments.view')->group(function () {
            Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
            Route::get('shipments/{shipment}', [ShipmentController::class, 'show'])->whereUuid('shipment')->name('shipments.show');
        });

        // On the road: tracking and proof of delivery.
        Route::middleware('can:logistics.deliveries.update')->group(function () {
            Route::post('shipments/{shipment}/track', [DeliveryController::class, 'track'])->whereUuid('shipment')->name('shipments.track');
            Route::post('shipments/{shipment}/deliver', [DeliveryController::class, 'deliver'])->whereUuid('shipment')->name('shipments.deliver');
            Route::post('shipments/{shipment}/fail', [DeliveryController::class, 'fail'])->whereUuid('shipment')->name('shipments.fail');
        });

        // The fleet.
        Route::middleware('can:logistics.fleet.manage')->group(function () {
            Route::get('vehicles/create', [VehicleController::class, 'create'])->name('vehicles.create');
            Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
            Route::get('vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->whereUuid('vehicle')->name('vehicles.edit');
            Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->whereUuid('vehicle')->name('vehicles.update');
            Route::post('vehicles/{vehicle}/maintenance', [VehicleController::class, 'maintain'])->whereUuid('vehicle')->name('vehicles.maintenance.store');

            Route::get('drivers/create', [DriverController::class, 'create'])->name('drivers.create');
            Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
            Route::get('drivers/{driver}/edit', [DriverController::class, 'edit'])->whereUuid('driver')->name('drivers.edit');
            Route::put('drivers/{driver}', [DriverController::class, 'update'])->whereUuid('driver')->name('drivers.update');
        });
        Route::middleware('can:logistics.fleet.view')->group(function () {
            Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
            Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])->whereUuid('vehicle')->name('vehicles.show');
            Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
        });

        // Trips and what they cost.
        Route::middleware('can:logistics.trips.manage')->group(function () {
            Route::get('trips/create', [TripController::class, 'create'])->name('trips.create');
            Route::post('trips', [TripController::class, 'store'])->name('trips.store');
            Route::post('trips/{trip}/shipments', [TripController::class, 'load'])->whereUuid('trip')->name('trips.shipments.store');
            Route::delete('trips/{trip}/shipments/{shipment}', [TripController::class, 'unload'])->whereUuid(['trip', 'shipment'])->name('trips.shipments.destroy');
            Route::post('trips/{trip}/dispatch', [TripController::class, 'dispatch'])->whereUuid('trip')->name('trips.dispatch');
            Route::post('trips/{trip}/complete', [TripController::class, 'complete'])->whereUuid('trip')->name('trips.complete');
            Route::post('trips/{trip}/cancel', [TripController::class, 'cancel'])->whereUuid('trip')->name('trips.cancel');
            Route::post('trips/{trip}/costs', [TripController::class, 'addCost'])->whereUuid('trip')->name('trips.costs.store');
            Route::delete('trips/{trip}/costs/{cost}', [TripController::class, 'removeCost'])->whereUuid(['trip', 'cost'])->name('trips.costs.destroy');
        });
        Route::middleware('can:logistics.trips.view')->group(function () {
            Route::get('trips', [TripController::class, 'index'])->name('trips.index');
            Route::get('trips/{trip}', [TripController::class, 'show'])->whereUuid('trip')->name('trips.show');
        });

        // Billing.
        Route::middleware('can:logistics.billing.manage')->group(function () {
            Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->whereUuid('invoice')->name('invoices.payments.store');
        });
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
            ->whereUuid('invoice')
            ->can('logistics.billing.void')
            ->name('invoices.void');
        Route::middleware('can:logistics.billing.view')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
        });
    });
