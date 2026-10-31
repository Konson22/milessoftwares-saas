import { BatchesScreen } from './batches';
import { DashboardScreen } from './dashboard';
import { ExpenseCategoriesScreen, ExpensesScreen } from './expenses';
import { MedicineFormScreen, MedicinesScreen } from './medicines';
import { ReceiptFormScreen, ReceiptScreen, ReceiptsScreen } from './receipts';
import { ReportsScreen } from './reports';
import { SettingsScreen } from './settings';
import { StockItemScreen, StockScreen } from './stock';
import { SuppliersScreen } from './suppliers';
import type { Screen } from '../types';
import { UsersScreen } from './users';

/** The screen that draws each page (its component, src/main/db/pages.ts). */
export const screens: Record<string, Screen> = {
  home: DashboardScreen,
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
