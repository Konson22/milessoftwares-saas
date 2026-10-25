import { BatchesScreen } from './screens/batches';
import { ExpenseCategoriesScreen, ExpensesScreen } from './screens/expenses';
import { MedicineFormScreen, MedicinesScreen } from './screens/medicines';
import { ReceiptFormScreen, ReceiptScreen, ReceiptsScreen } from './screens/receipts';
import { ReportsScreen } from './screens/reports';
import { SettingsScreen } from './screens/settings';
import { StockItemScreen, StockScreen } from './screens/stock';
import { SuppliersScreen } from './screens/suppliers';
import { UsersScreen } from './screens/users';
import type { Screen } from './types';

/**
 * The page (its component, src/local/db/pages.ts) each native screen draws.
 * The dashboard ("home") is the Home tab. A page without one here is shown
 * by GenericScreen, as data.
 */
export const screens: Record<string, Screen> = {
  'medicines/index': MedicinesScreen,
  'medicines/form': MedicineFormScreen,
  'stock/index': StockScreen,
  'stock/show': StockItemScreen,
  'batches/index': BatchesScreen,
  'suppliers/index': SuppliersScreen,
  'receipts/index': ReceiptsScreen,
  'receipts/form': ReceiptFormScreen,
  'receipts/show': ReceiptScreen,
  'expenses/index': ExpensesScreen,
  'expenses/categories': ExpenseCategoriesScreen,
  'reports/index': ReportsScreen,
  'users/index': UsersScreen,
  'settings/index': SettingsScreen,
};
