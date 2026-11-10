<?php

namespace App\Petrol;

/**
 * Catalog permission keys (platform/catalog.json). Routes and services
 * use these so a renamed key cannot drift from the catalog.
 */
final class Permissions
{
    public const SALES_VIEW = 'petrol.sales.view';

    public const SALES_CREATE = 'petrol.sales.create';

    public const SALES_VOID = 'petrol.sales.void';

    public const SHIFTS_VIEW = 'petrol.shifts.view';

    public const SHIFTS_OPEN = 'petrol.shifts.open';

    public const SHIFTS_CLOSE = 'petrol.shifts.close';

    public const SHIFTS_APPROVE = 'petrol.shifts.approve';

    public const ASSIGNMENTS_VIEW = 'petrol.assignments.view';

    public const ASSIGNMENTS_CREATE = 'petrol.assignments.create';

    public const ASSIGNMENTS_REASSIGN = 'petrol.assignments.reassign';

    public const HANDOVERS_INITIATE = 'petrol.handovers.initiate';

    public const HANDOVERS_CONFIRM = 'petrol.handovers.confirm';

    public const HANDOVERS_APPROVE = 'petrol.handovers.approve';

    public const METERS_RECORD = 'petrol.meters.record';

    public const EXCEPTIONS_REVIEW = 'petrol.exceptions.review';

    public const EXCEPTIONS_RESOLVE = 'petrol.exceptions.resolve';

    public const ATTENDANTS_VIEW = 'petrol.attendants.view';

    public const ATTENDANTS_MANAGE = 'petrol.attendants.manage';

    public const PUMPS_VIEW = 'petrol.pumps.view';

    public const PUMPS_MANAGE = 'petrol.pumps.manage';

    public const TANKS_VIEW = 'petrol.tanks.view';

    public const TANKS_MANAGE = 'petrol.tanks.manage';

    public const STOCK_ADJUST = 'petrol.stock.adjust';

    public const MEASUREMENTS_VIEW = 'petrol.measurements.view';

    public const MEASUREMENTS_RECORD = 'petrol.measurements.record';

    public const MEASUREMENTS_VERIFY = 'petrol.measurements.verify';

    public const CHARTS_MANAGE = 'petrol.charts.manage';

    public const RECEIVING_RECORD = 'petrol.receiving.record';

    public const RECEIVING_APPROVE = 'petrol.receiving.approve';

    public const CALIBRATION_RECORD = 'petrol.calibration.record';

    public const CALIBRATION_APPROVE = 'petrol.calibration.approve';

    public const EQUIPMENT_MANAGE = 'petrol.equipment.manage';

    public const RECONCILIATION_VIEW = 'petrol.reconciliation.view';

    public const VARIANCES_REVIEW = 'petrol.variances.review';

    public const METERS_CORRECT = 'petrol.meters.correct';

    public const PRODUCTS_MANAGE = 'petrol.products.manage';

    public const PRICES_MANAGE = 'petrol.prices.manage';

    public const PURCHASES_VIEW = 'petrol.purchases.view';

    public const PURCHASES_MANAGE = 'petrol.purchases.manage';

    public const CUSTOMERS_VIEW = 'petrol.customers.view';

    public const CUSTOMERS_MANAGE = 'petrol.customers.manage';

    public const EXPENSES_MANAGE = 'petrol.expenses.manage';

    public const EXPENSE_CATEGORIES = 'petrol.expenses.categories';

    public const RATES_MANAGE = 'petrol.rates.manage';

    public const CURRENCIES_VIEW = 'petrol.currencies.view';

    public const CURRENCIES_MANAGE = 'petrol.currencies.manage';

    public const CURRENCIES_ENABLE = 'petrol.currencies.enable';

    public const CASH_REPORT = 'petrol.cash.report';

    public const SERVICE_MANAGE = 'petrol.service.manage';

    public const SERVICE_WORK = 'petrol.service.work';

    public const STORE_MANAGE = 'petrol.store.manage';

    public const STORE_MOVE = 'petrol.store.move';

    public const TRANSFERS_MANAGE = 'petrol.transfers.manage';

    public const TRANSFERS_APPROVE = 'petrol.transfers.approve';

    public const CASH_MANAGE = 'petrol.cash.manage';

    public const CASH_VIEW = 'petrol.cash.view';

    public const CASH_SUBMIT = 'petrol.cash.submit';

    public const CASH_CONFIRM = 'petrol.cash.confirm';

    public const CASH_REJECT = 'petrol.cash.reject';

    public const CASH_TRANSFER = 'petrol.cash.transfer';

    public const CASH_SAFES = 'petrol.cash.safes';

    public const CASH_DEPOSIT = 'petrol.cash.deposit';

    public const CASH_DEPOSIT_CONFIRM = 'petrol.cash.deposit_confirm';

    public const CASH_RECONCILE = 'petrol.cash.reconcile';

    public const CASH_ADJUST = 'petrol.cash.adjust';

    public const FINANCE_VIEW = 'petrol.finance.view';

    public const REPORTS_VIEW = 'petrol.reports.view';

    public const STATIONS_MANAGE = 'petrol.stations.manage';

    public const AUDIT_VIEW = 'petrol.audit.view';

    public const PAYABLES_PAY = 'petrol.payables.pay';

    public const STATIONS_FEATURE = 'petrol.stations';

    public const ATTENDANTS_FEATURE = 'petrol.attendants';
}
